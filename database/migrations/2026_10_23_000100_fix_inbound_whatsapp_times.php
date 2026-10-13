<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Incoming WhatsApp messages were stored with the UTC wall clock instead of the
 * application's (7 hours early in Asia/Jakarta). The tell-tale is a message whose
 * sent_at is about one offset earlier than the moment the row was created, since a
 * webhook arrives within seconds of sending. Those rows are moved forward by the
 * offset; correct rows (sent_at ~ created_at) are left alone, so running this twice
 * changes nothing. Conversation times that were copied from them are then recomputed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $offset = CarbonImmutable::now(config('app.timezone'))->utcOffset(); // minutes

        if ($offset <= 0) {
            return; // UTC or west of it: the two clocks agree or cannot be told apart.
        }

        $seconds = $offset * 60;
        $touched = [];

        DB::table('wa_messages')->where('direction', 'in')->select(['id', 'conversation_id', 'sent_at', 'created_at'])
            ->chunkById(500, function ($rows) use ($seconds, &$touched) {
                foreach ($rows as $row) {
                    $sent = CarbonImmutable::parse($row->sent_at);
                    $gap = CarbonImmutable::parse($row->created_at)->getTimestamp() - $sent->getTimestamp();

                    // Early by one offset, give or take 30 minutes for a slow delivery.
                    if ($gap < $seconds - 1800 || $gap > $seconds + 1800) {
                        continue;
                    }

                    $fixed = $sent->addSeconds($seconds);

                    DB::table('wa_messages')->where('id', $row->id)->update(['sent_at' => $fixed]);
                    // The first ad message of a conversation also set ad_entry_at to its (wrong) time.
                    DB::table('wa_conversations')->where('id', $row->conversation_id)->where('ad_entry_at', $sent)->update(['ad_entry_at' => $fixed]);
                    $touched[$row->conversation_id] = true;
                }
            });

        foreach (array_chunk(array_keys($touched), 500) as $ids) {
            foreach (DB::table('wa_conversations')->whereIn('id', $ids)->pluck('id') as $id) {
                $lastInbound = DB::table('wa_messages')->where('conversation_id', $id)->where('direction', 'in')->max('sent_at');
                $lastMessage = DB::table('wa_messages')->where('conversation_id', $id)->max('sent_at');

                DB::table('wa_conversations')->where('id', $id)->update(['last_inbound_at' => $lastInbound, 'last_message_at' => $lastMessage]);
            }
        }
    }

    public function down(): void
    {
        // Data repair: nothing to undo.
    }
};
