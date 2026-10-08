<?php

namespace App\WhatsApp;

enum RouteKind: string
{
    /** Official number, inside the free 72-hour window after replying to an ad. */
    case FreeWindow = 'free_window';

    /** Official number, answering a client who wrote in the last 24 hours. */
    case ReplyOfficial = 'reply_official';

    /** Gateway number, answering a client who wrote to it in the last 24 hours. */
    case ReplyGateway = 'reply_gateway';

    /** Gateway number, starting a follow-up after every free window has closed. */
    case FollowUpGateway = 'follow_up_gateway';

    /** Official number, template only, billed by Meta. Needs the agent's confirmation. */
    case PaidTemplate = 'paid_template';

    /** No connected number can send right now. */
    case Unavailable = 'unavailable';
}
