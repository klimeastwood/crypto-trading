<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Registration cannot be completed because the shared CRM data is missing or
 * misconfigured. The message is shown to the client as a form error.
 */
class RegistrationException extends RuntimeException {}
