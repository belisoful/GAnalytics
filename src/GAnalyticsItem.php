<?php

/**
 * GAnalyticsItem class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/belisoful/GAnalytics/blob/main/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * GAnalyticsItem class.
 *
 * GAnalyticsItem is one entry of the `items` of a GA4 ecommerce event
 * ({@see GAnalyticsEcommerce}). Its properties are the item parameters Google defines; a
 * property left unset is left out of {@see toArray()}, and {@see setParam()} adds a custom item
 * parameter.
 *
 * ```php
 * $item = new GAnalyticsItem(['ItemId' => 'SKU-1', 'ItemName' => 'Mug', 'Price' => 9.99, 'Quantity' => 2]);
 * $module->trackEcommerce('add_to_cart', ['currency' => 'USD', 'value' => 19.98, 'items' => [$item]]);
 * ```
 *
 * | Property | Parameter |
 * |---|---|
 * | `ItemId`, `ItemName` | `item_id`, `item_name`; one of the two is required |
 * | `Price`, `Quantity`, `Discount` | `price`, `quantity`, `discount` |
 * | `ItemBrand`, `ItemVariant`, `Affiliation`, `Coupon` | `item_brand`, `item_variant`, `affiliation`, `coupon` |
 * | `ItemCategory` … `ItemCategory5` | `item_category` … `item_category5` |
 * | `ItemListId`, `ItemListName`, `Index` | `item_list_id`, `item_list_name`, `index` |
 * | `LocationId` | `location_id` |
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsItem extends TComponent
{
	/** @var array<string, mixed> parameter => value, of the set parameters */
	private array $_params = [];

	/**
	 * @param array<string, mixed> $properties property => value, such as `['ItemId' => 'SKU-1', 'Price' => 9.99]`
	 */
	public function __construct(array $properties = [])
	{
		parent::__construct();
		foreach ($properties as $property => $value) {
			$this->setSubProperty($property, $value);
		}
	}

	/**
	 * @return ?string The item id, such as a SKU (`item_id`).
	 */
	public function getItemId(): ?string
	{
		return $this->_params['item_id'] ?? null;
	}

	/**
	 * @param mixed $value The item id, such as a SKU; empty for none.
	 */
	public function setItemId($value): void
	{
		$this->setParam('item_id', $this->ensureText($value));
	}

	/**
	 * @return ?string The item name (`item_name`).
	 */
	public function getItemName(): ?string
	{
		return $this->_params['item_name'] ?? null;
	}

	/**
	 * @param mixed $value The item name; empty for none.
	 */
	public function setItemName($value): void
	{
		$this->setParam('item_name', $this->ensureText($value));
	}

	/**
	 * @return ?string The store or affiliation (`affiliation`).
	 */
	public function getAffiliation(): ?string
	{
		return $this->_params['affiliation'] ?? null;
	}

	/**
	 * @param mixed $value The store or affiliation; empty for none.
	 */
	public function setAffiliation($value): void
	{
		$this->setParam('affiliation', $this->ensureText($value));
	}

	/**
	 * @return ?string The item coupon (`coupon`).
	 */
	public function getCoupon(): ?string
	{
		return $this->_params['coupon'] ?? null;
	}

	/**
	 * @param mixed $value The item coupon; empty for none.
	 */
	public function setCoupon($value): void
	{
		$this->setParam('coupon', $this->ensureText($value));
	}

	/**
	 * @return ?string The brand (`item_brand`).
	 */
	public function getItemBrand(): ?string
	{
		return $this->_params['item_brand'] ?? null;
	}

	/**
	 * @param mixed $value The brand; empty for none.
	 */
	public function setItemBrand($value): void
	{
		$this->setParam('item_brand', $this->ensureText($value));
	}

	/**
	 * @return ?string The category (`item_category`).
	 */
	public function getItemCategory(): ?string
	{
		return $this->_params['item_category'] ?? null;
	}

	/**
	 * @param mixed $value The category; empty for none.
	 */
	public function setItemCategory($value): void
	{
		$this->setParam('item_category', $this->ensureText($value));
	}

	/**
	 * @return ?string The second category level (`item_category2`).
	 */
	public function getItemCategory2(): ?string
	{
		return $this->_params['item_category2'] ?? null;
	}

	/**
	 * @param mixed $value The second category level; empty for none.
	 */
	public function setItemCategory2($value): void
	{
		$this->setParam('item_category2', $this->ensureText($value));
	}

	/**
	 * @return ?string The third category level (`item_category3`).
	 */
	public function getItemCategory3(): ?string
	{
		return $this->_params['item_category3'] ?? null;
	}

	/**
	 * @param mixed $value The third category level; empty for none.
	 */
	public function setItemCategory3($value): void
	{
		$this->setParam('item_category3', $this->ensureText($value));
	}

	/**
	 * @return ?string The fourth category level (`item_category4`).
	 */
	public function getItemCategory4(): ?string
	{
		return $this->_params['item_category4'] ?? null;
	}

	/**
	 * @param mixed $value The fourth category level; empty for none.
	 */
	public function setItemCategory4($value): void
	{
		$this->setParam('item_category4', $this->ensureText($value));
	}

	/**
	 * @return ?string The fifth category level (`item_category5`).
	 */
	public function getItemCategory5(): ?string
	{
		return $this->_params['item_category5'] ?? null;
	}

	/**
	 * @param mixed $value The fifth category level; empty for none.
	 */
	public function setItemCategory5($value): void
	{
		$this->setParam('item_category5', $this->ensureText($value));
	}

	/**
	 * @return ?string The id of the list the item was shown in (`item_list_id`).
	 */
	public function getItemListId(): ?string
	{
		return $this->_params['item_list_id'] ?? null;
	}

	/**
	 * @param mixed $value The id of the list the item was shown in; empty for none.
	 */
	public function setItemListId($value): void
	{
		$this->setParam('item_list_id', $this->ensureText($value));
	}

	/**
	 * @return ?string The name of the list the item was shown in (`item_list_name`).
	 */
	public function getItemListName(): ?string
	{
		return $this->_params['item_list_name'] ?? null;
	}

	/**
	 * @param mixed $value The name of the list the item was shown in; empty for none.
	 */
	public function setItemListName($value): void
	{
		$this->setParam('item_list_name', $this->ensureText($value));
	}

	/**
	 * @return ?string The variant, such as a size or color (`item_variant`).
	 */
	public function getItemVariant(): ?string
	{
		return $this->_params['item_variant'] ?? null;
	}

	/**
	 * @param mixed $value The variant, such as a size or color; empty for none.
	 */
	public function setItemVariant($value): void
	{
		$this->setParam('item_variant', $this->ensureText($value));
	}

	/**
	 * @return ?string The Google Place id of the store location (`location_id`).
	 */
	public function getLocationId(): ?string
	{
		return $this->_params['location_id'] ?? null;
	}

	/**
	 * @param mixed $value The Google Place id of the store location; empty for none.
	 */
	public function setLocationId($value): void
	{
		$this->setParam('location_id', $this->ensureText($value));
	}

	/**
	 * @return ?float The unit price.
	 */
	public function getPrice(): ?float
	{
		return $this->_params['price'] ?? null;
	}

	/**
	 * @param mixed $value The unit price; empty for none.
	 */
	public function setPrice($value): void
	{
		$this->setParam('price', $this->ensureNumber($value, false));
	}

	/**
	 * @return ?int The quantity.
	 */
	public function getQuantity(): ?int
	{
		return $this->_params['quantity'] ?? null;
	}

	/**
	 * @param mixed $value The quantity; empty for none.
	 */
	public function setQuantity($value): void
	{
		$this->setParam('quantity', $this->ensureNumber($value, true));
	}

	/**
	 * @return ?float The unit discount.
	 */
	public function getDiscount(): ?float
	{
		return $this->_params['discount'] ?? null;
	}

	/**
	 * @param mixed $value The unit discount; empty for none.
	 */
	public function setDiscount($value): void
	{
		$this->setParam('discount', $this->ensureNumber($value, false));
	}

	/**
	 * @return ?int The item's position in its list.
	 */
	public function getIndex(): ?int
	{
		return $this->_params['index'] ?? null;
	}

	/**
	 * @param mixed $value The item's position in its list; empty for none.
	 */
	public function setIndex($value): void
	{
		$this->setParam('index', $this->ensureNumber($value, true));
	}

	/**
	 * Sets an item parameter, such as a custom one.
	 * @param string $name The parameter name.
	 * @param mixed $value The value; null removes the parameter.
	 * @return static The item.
	 */
	public function setParam(string $name, mixed $value): static
	{
		if ($value === null) {
			unset($this->_params[$name]);
		} else {
			$this->_params[$name] = $value;
		}
		return $this;
	}

	/**
	 * @return array<string, mixed> The set parameters, as the `items` entry of an event.
	 */
	public function toArray(): array
	{
		return $this->_params;
	}

	/**
	 * @param mixed $value A string, or empty.
	 * @return ?string The trimmed string; null when empty.
	 */
	protected function ensureText(mixed $value): ?string
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		return $value === null ? null : \trim((string) TPropertyValue::ensureString($value));
	}

	/**
	 * @param mixed $value A number, a numeric string, or empty.
	 * @param bool $integer Whether an integer is kept.
	 * @return null|float|int The number; null when empty.
	 */
	protected function ensureNumber(mixed $value, bool $integer): null|float|int
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		if ($value === null) {
			return null;
		}
		return $integer ? TPropertyValue::ensureInteger($value) : TPropertyValue::ensureFloat($value);
	}
}
