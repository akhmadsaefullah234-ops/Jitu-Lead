<?php

namespace App\Filament\Pages;

use App\Filament\Support\ShipmentActions;
use App\Models\Lead;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Shipments\ResiImport;
use App\Shipping\Shipments\Shipments;
use App\Support\SpreadsheetReader;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * "Impor resi" (lead list): tracking numbers made in the courier aggregator's own app, from its CSV/XLSX export,
 * linked to this agency's leads. Upload, pick the columns when their names differ, check the matches (pick a lead
 * or skip each unsure row), then save: only the rows confirmed are linked (App\Shipping\Shipments\Shipments::link).
 */
class ImportResi extends Page
{
    public const MAX_ROWS = 500;

    protected string $view = 'filament.pages.import-resi';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $title = 'Impor resi';

    protected static bool $shouldRegisterNavigation = false;

    /** @var list<string> */
    public array $header = [];

    /** @var list<list<string>> */
    public array $cells = [];

    public string $fileName = '';

    /** @var array{awb: ?int, name: ?int, phone: ?int, courier: ?int} */
    public array $columns = ['awb' => null, 'name' => null, 'phone' => null, 'courier' => null];

    /** @var list<array> */
    public array $matches = [];

    /** @var array<int, string> row index => lead id, '' to skip */
    public array $choices = [];

    public bool $notify = false;

    public bool $closeDelivered = true;

    /** @var list<string> */
    public array $outcome = [];

    public static function canAccess(): bool
    {
        return ShipmentActions::available() && (auth()->user()?->can('create', Lead::class) ?? false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')->label($this->cells === [] ? 'Unggah file ekspor' : 'Ganti file')->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading('Unggah ekspor resi')->modalDescription('CSV atau XLSX dari aplikasi/dashboard penyedia, maksimal '.self::MAX_ROWS.' baris. Baris pertama berisi judul kolom.')
                ->modalSubmitActionLabel('Baca file')
                ->schema([
                    FileUpload::make('file')->label('File')->required()->disk('local')->directory('imports')->visibility('private')->storeFiles(true)->maxSize(4096)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->preserveFilenames(false),
                ])
                ->action(function (array $data) {
                    $path = Storage::disk('local')->path($data['file']);

                    try {
                        $this->read($path, (string) $data['file']);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->title('File tidak bisa dibaca')->body($e->getMessage())->danger()->send();
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }
                }),
        ];
    }

    /** Reads the file and guesses the columns. Public for tests. */
    public function read(string $path, string $name): void
    {
        $rows = SpreadsheetReader::rows($path, $name, self::MAX_ROWS + 1);

        if (count($rows) < 2) {
            throw new InvalidArgumentException('File kosong atau hanya berisi judul kolom.');
        }

        if (count($rows) - 1 > self::MAX_ROWS) {
            throw new InvalidArgumentException('Maksimal '.self::MAX_ROWS.' baris per impor. Pecah file Anda.');
        }

        $this->header = array_map(fn ($h) => mb_substr((string) $h, 0, 60), $rows[0]);
        $this->cells = array_slice($rows, 1);
        $this->fileName = basename($name);
        $this->columns = ResiImport::guessColumns($this->header);
        $this->outcome = [];
        $this->rematch();
    }

    public function updatedColumns(): void
    {
        $this->columns = array_map(fn ($v) => $v === '' || $v === null ? null : (int) $v, $this->columns);
        $this->rematch();
    }

    public function rematch(): void
    {
        $this->matches = $this->columns['awb'] === null ? [] : ResiImport::match($this->cells, $this->columns);
        $this->choices = [];

        foreach ($this->matches as $i => $m) {
            // Sure matches are ticked; unsure ones are proposed but wait for a person; the rest is skipped.
            $this->choices[$i] = $m['status'] === ResiImport::MATCHED ? (string) $m['lead_id'] : '';
        }
    }

    /** Links the rows that have a lead chosen. */
    public function save(): void
    {
        $service = app(Shipments::class);
        $linked = 0;
        $failed = [];

        foreach ($this->matches as $i => $m) {
            $leadId = (int) ($this->choices[$i] ?? 0);

            if ($leadId === 0 || in_array($m['status'], [ResiImport::INVALID, ResiImport::LINKED], true)) {
                continue;
            }

            $lead = Lead::query()->find($leadId);

            try {
                if ($lead === null) {
                    throw new CarrierException('Lead tidak ditemukan.');
                }

                $service->link($lead, $m['awb'], $m['courier'] ?: 'JNE', auth()->user(), $this->notify, $this->closeDelivered);
                $linked++;
            } catch (CarrierException $e) {
                $failed[] = 'Baris '.$m['row'].' ('.$m['awb'].'): '.$e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $failed[] = 'Baris '.$m['row'].' ('.$m['awb'].'): gagal disimpan.';
            }
        }

        $this->outcome = $failed;
        $this->rematch();

        Notification::make()->title($linked.' resi terhubung')->body($failed !== [] ? count($failed).' gagal: '.implode('; ', array_slice($failed, 0, 5)) : null)
            ->{$failed === [] ? 'success' : 'warning'}()->persistent()->send();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            ResiImport::MATCHED => 'Cocok', ResiImport::AMBIGUOUS => 'Perlu dipilih', ResiImport::UNMATCHED => 'Tidak cocok',
            ResiImport::LINKED => 'Sudah terhubung', default => 'Tidak valid',
        };
    }
}
