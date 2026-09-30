<?php

/**
 * GAnalyticsEcommerce class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/belisoful/GAnalytics/blob/main/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TInvalidDataValueException;

/**
 * GAnalyticsEcommerce class.
 *
 * GAnalyticsEcommerce checks and normalizes the parameters of the GA4 ecommerce events, so a
 * malformed purchase is refused in PHP instead of being dropped silently by Google.
 * {@see GAnalyticsModule::trackEcommerce()} and {@see GAnalyticsModule::sendEcommerce()} use it.
 *
 * ```php
 * $module->trackEcommerce('purchase', [
 *     'transaction_id' => 'T-1001', 'currency' => 'usd', 'value' => 19.98, 'shipping' => 4.5,
 *     'items' => [new GAnalyticsItem(['ItemId' => 'SKU-1', 'ItemName' => 'Mug', 'Price' => 9.99, 'Quantity' => 2])],
 * ]);
 * ```
 *
 * | Rule | Events |
 * |---|---|
 * | `items` holds 1 to {@see MAX_ITEMS} items | every event except `refund`, `select_promotion` and `view_promotion`, where it is optional |
 * | `transaction_id` is required | `purchase`, `refund` |
 * | `currency` is required with `value` | every event |
 * | an item has an `item_id` or an `item_name` | every item |
 *
 * `currency` is uppercased and must be three letters (ISO 4217). `value`, `tax`, `shipping`,
 * and an item's `price` and `discount` are numbers; an item's `quantity` and `index` are
 * integers. An item is a {@see GAnalyticsItem} or an array of item parameters.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsEcommerce
{
	/** The GA4 ecommerce events, with whether `items` is required. */
	public const EVENTS = [
		'add_payment_info' => true,
		'add_shipping_info' => true,
		'add_to_cart' => true,
		'add_to_wishlist' => true,
		'begin_checkout' => true,
		'purchase' => true,
		'refund' => false,
		'remove_from_cart' => true,
		'select_item' => true,
		'select_promotion' => false,
		'view_cart' => true,
		'view_item' => true,
		'view_item_list' => true,
		'view_promotion' => false,
	];

	/** The events that need a `transaction_id`. */
	public const TRANSACTION_EVENTS = ['purchase', 'refund'];

	/** The most items an event carries. */
	public const MAX_ITEMS = 200;

	/** The event parameters that are numbers. */
	public const NUMBER_PARAMS = ['value', 'tax', 'shipping'];

	/** The item parameters that are numbers, and whether each is an integer. */
	public const ITEM_NUMBER_PARAMS = ['price' => false, 'discount' => false, 'quantity' => true, 'index' => true];

	/**
	 * Checks an ecommerce event's parameters and returns them normalized: items as arrays,
	 * numbers as numbers, the currency uppercased.
	 * @param string $event The ecommerce event, one of {@see EVENTS}.
	 * @param array<string, mixed> $params The event parameters.
	 * @throws TInvalidDataValueException When the event is not an ecommerce event, or a rule is broken.
	 * @return array<string, mixed> The parameters.
	 */
	public static function params(string $event, array $params): array
	{
		if (!isset(static::EVENTS[$event])) {
			throw new TInvalidDataValueException('ganalytics_ecommerce_event_invalid', $event, \implode(', ', \array_keys(static::EVENTS)));
		}
		foreach (static::NUMBER_PARAMS as $name) {
			if (\array_key_exists($name, $params)) {
				$params[$name] = static::number($event, $name, $params[$name], false);
			}
		}
		if (isset($params['currency'])) {
			$params['currency'] = \strtoupper(\trim((string) $params['currency']));
			if (!\preg_match('/^[A-Z]{3}$/', $params['currency'])) {
				throw new TInvalidDataValueException('ganalytics_ecommerce_invalid', $event, 'currency must be a three-letter ISO 4217 code');
			}
		} elseif (\array_key_exists('value', $params)) {
			throw new TInvalidDataValueException('ganalytics_ecommerce_invalid', $event, 'value needs a currency');
		}
		if (\in_array($event, static::TRANSACTION_EVENTS, true) && \trim((string) ($params['transaction_id'] ?? '')) === '') {
			throw new TInvalidDataValueException('ganalytics_ecommerce_invalid', $event, 'transaction_id is required');
		}
		$items = $params['items'] ?? [];
		if (!\is_array($items)) {
			throw new TInvalidDataValueException('ganalytics_ecommerce_invalid', $event, 'items must be a list');
		}
		if (\count($items) > static::MAX_ITEMS) {
			throw new TInvalidDataValueException('ganalytics_ecommerce_invalid', $event, 'at most ' . static::MAX_ITEMS . ' items are allowed');
		}
		if (\count($items) === 0) {
			if (static::EVENTS[$event]) {
				throw new TInvalidDataValueException('ganalytics_ecommerce_invalid', $event, 'items are required');
			}
			unset($params['items']);
			return $params;
		}
		$params['items'] = \array_map(fn ($item) => static::item($event, $item), \array_values($items));
		return $params;
	}

	/**
	 * @param string $event The event, for the error message.
	 * @param mixed $item A {@see GAnalyticsItem} or an array of item parameters.
	 * @throws TInvalidDataValueException When the item is neither, has no id or name, or has a bad number.
	 * @return array<string, mixed> The item parameters.
	 */
	protected static function item(string $event, mixed $item): array
	{
		if ($item instanceof GAnalyticsItem) {
			$item = $item->toArray();
		} elseif (!\is_array($item)) {
			throw new TInvalidDataValueException('ganalytics_ecommerce_invalid', $event, 'an item must be a GAnalyticsItem or an array');
		}
		if (\trim((string) ($item['item_id'] ?? '')) === '' && \trim((string) ($item['item_name'] ?? '')) === '') {
			throw new TInvalidDataValueException('ganalytics_ecommerce_invalid', $event, 'an item needs an item_id or an item_name');
		}
		foreach (static::ITEM_NUMBER_PARAMS as $name => $integer) {
			if (\array_key_exists($name, $item)) {
				$item[$name] = static::number($event, 'item ' . $name, $item[$name], $integer);
			}
		}
		return $item;
	}

	/**
	 * @param string $event The event, for the error message.
	 * @param string $name The parameter, for the error message.
	 * @param mixed $value The value.
	 * @param bool $integer Whether the value must be an integer.
	 * @throws TInvalidDataValueException When the value is not a number, or not an integer where one is required.
	 * @return float|int The number.
	 */
	protected static function number(string $event, string $name, mixed $value, bool $integer): float|int
	{
		if (!\is_numeric($value) || ($integer && (float) $value !== (float) (int) $value)) {
			throw new TInvalidDataValueException('ganalytics_ecommerce_invalid', $event, $name . ' must be ' . ($integer ? 'an integer' : 'a number'));
		}
		return $integer ? (int) $value : (float) $value;
	}
}
