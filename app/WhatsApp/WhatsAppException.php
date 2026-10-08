<?php

namespace App\WhatsApp;

use RuntimeException;

/**
 * A message that cannot be sent, with a reason that is safe to show the agent.
 */
class WhatsAppException extends RuntimeException {}
