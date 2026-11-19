<?php

declare(strict_types=1);

namespace Drupal\kit_assistant;

/**
 * Thrown when the OpenAI API can't be reached or returns something unusable.
 *
 * Always caught at the controller boundary and turned into a plain,
 * friendly chat message -- a visitor never sees a stack trace or an API
 * error string.
 */
class OpenAiException extends \RuntimeException {}
