<?php

declare(strict_types=1);

namespace Drupal\product_kit;

/**
 * Converts prices between minor currency units and their decimal notation.
 *
 * Prices are stored as integers in minor units (cents): 1050 is "10.50". This
 * is the one place that knows about the factor of 100, so forms, list builders
 * and field plugins all agree on it. Arithmetic stays on integers throughout —
 * no float ever touches a price, so nothing is rounded.
 *
 * Single-currency for now (see the README's known limitations): the output is
 * a bare decimal amount, without a currency symbol or locale-specific
 * separators.
 */
class PriceFormatter {

  /**
   * Minor units in one major unit, for a two-decimal currency.
   */
  protected const MINOR_PER_MAJOR = 100;

  /**
   * Most digits accepted before the decimal point.
   *
   * Keeps amount * 100 comfortably inside a signed 64-bit integer, which is
   * what the price columns hold.
   */
  protected const MAX_WHOLE_DIGITS = 16;

  /**
   * Formats an amount in minor units as a decimal string.
   *
   * @param int $minor
   *   The amount in minor units, e.g. 1050.
   * @param bool $grouping
   *   Whether to group thousands ("1,234.50"). Pass FALSE for a value that is
   *   going back into a form field, which ::parse() must be able to read.
   *
   * @return string
   *   E.g. "10.50".
   */
  public function format(int $minor, bool $grouping = TRUE): string {
    $sign = $minor < 0 ? '-' : '';
    $absolute = abs($minor);
    $whole = intdiv($absolute, self::MINOR_PER_MAJOR);
    $fraction = $absolute % self::MINOR_PER_MAJOR;

    return $sign
      . ($grouping ? number_format($whole, 0, '.', ',') : (string) $whole)
      . '.'
      . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
  }

  /**
   * Parses a decimal amount typed by a person into minor units.
   *
   * Accepts digits with an optional fraction of one or two digits, using "."
   * or "," as the decimal mark ("10", "10.5", "10,50"). Thousands separators
   * are rejected rather than guessed at: "1,000" could mean one thousand or
   * one, and silently choosing wrong would misprice a product.
   *
   * @return int|null
   *   The amount in minor units, or NULL if the input is not a valid amount.
   */
  public function parse(string $input): ?int {
    $pattern = '/^(\d{1,' . self::MAX_WHOLE_DIGITS . '})(?:[.,](\d{1,2}))?$/';
    if (!preg_match($pattern, trim($input), $matches)) {
      return NULL;
    }

    $fraction = str_pad($matches[2] ?? '', 2, '0');
    return (int) $matches[1] * self::MINOR_PER_MAJOR + (int) $fraction;
  }

}
