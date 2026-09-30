<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsEcommerce;
use belisoful\GAnalytics\GAnalyticsItem;
use belisoful\GAnalytics\GAnalyticsModule;
use belisoful\GAnalytics\GAnalyticsPageBehavior;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\Web\UI\TPage;

class GAnalyticsEcommerceTest extends TestCase
{
	protected function tearDown(): void
	{
		TComponent::detachClassBehavior(GAnalyticsModule::PAGE_BEHAVIOR_NAME, TPage::class);
	}

	private function module(): ProbeGAnalyticsModule
	{
		$module = new ProbeGAnalyticsModule();
		$module->setAttachPageBehavior(false);
		$module->setAmendCsp(false);
		$module->setMeasurementId('G-TEST1234AB');
		$module->setApiSecret('s3cret');
		return $module;
	}

	private function refused(string $event, array $params, string $code = 'ganalytics_ecommerce_invalid', string $reason = ''): void
	{
		try {
			GAnalyticsEcommerce::params($event, $params);
			self::fail($event . ' should be refused: ' . $reason);
		} catch (TInvalidDataValueException $e) {
			self::assertSame($code, $e->getErrorCode());
			if ($reason !== '') {
				self::assertStringContainsString($reason, $e->getMessage());
			}
		}
	}

	public function testItemProperties()
	{
		$item = new GAnalyticsItem([
			'ItemId' => ' SKU-1 ', 'ItemName' => 'Mug', 'Affiliation' => 'Store', 'Coupon' => 'SAVE',
			'ItemBrand' => 'Acme', 'ItemCategory' => 'Kitchen', 'ItemCategory2' => 'Cups', 'ItemCategory3' => 'Mugs',
			'ItemCategory4' => 'Ceramic', 'ItemCategory5' => 'Large', 'ItemListId' => 'related', 'ItemListName' => 'Related',
			'ItemVariant' => 'blue', 'LocationId' => 'ChIJ', 'Price' => '9.99', 'Quantity' => '2', 'Discount' => 1, 'Index' => '3',
		]);
		self::assertSame('SKU-1', $item->getItemId());
		self::assertSame('Mug', $item->getItemName());
		self::assertSame('Store', $item->getAffiliation());
		self::assertSame('SAVE', $item->getCoupon());
		self::assertSame('Acme', $item->getItemBrand());
		self::assertSame('Kitchen', $item->getItemCategory());
		self::assertSame('Cups', $item->getItemCategory2());
		self::assertSame('Mugs', $item->getItemCategory3());
		self::assertSame('Ceramic', $item->getItemCategory4());
		self::assertSame('Large', $item->getItemCategory5());
		self::assertSame('related', $item->getItemListId());
		self::assertSame('Related', $item->getItemListName());
		self::assertSame('blue', $item->getItemVariant());
		self::assertSame('ChIJ', $item->getLocationId());
		self::assertSame(9.99, $item->getPrice());
		self::assertSame(2, $item->getQuantity());
		self::assertSame(1.0, $item->getDiscount());
		self::assertSame(3, $item->getIndex());
		self::assertSame([
			'item_id' => 'SKU-1', 'item_name' => 'Mug', 'affiliation' => 'Store', 'coupon' => 'SAVE', 'item_brand' => 'Acme',
			'item_category' => 'Kitchen', 'item_category2' => 'Cups', 'item_category3' => 'Mugs', 'item_category4' => 'Ceramic',
			'item_category5' => 'Large', 'item_list_id' => 'related', 'item_list_name' => 'Related', 'item_variant' => 'blue',
			'location_id' => 'ChIJ', 'price' => 9.99, 'quantity' => 2, 'discount' => 1.0, 'index' => 3,
		], $item->toArray());
	}

	public function testItemUnsetAndCustomParameters()
	{
		$item = new GAnalyticsItem();
		self::assertSame([], $item->toArray());
		self::assertNull($item->getItemId());
		self::assertNull($item->getPrice());
		self::assertNull($item->getQuantity());
		$item->setItemName('Mug');
		$item->setPrice(4);
		$item->setParam('color_code', 'b1');
		self::assertSame(['item_name' => 'Mug', 'price' => 4.0, 'color_code' => 'b1'], $item->toArray());
		$item->setItemName(' ');
		$item->setPrice('');
		$item->setParam('color_code', null);
		self::assertSame([], $item->toArray(), 'empty values remove the parameters');
		$item->setQuantity('0');
		self::assertSame(['quantity' => 0], $item->toArray(), "'0' is a value");
	}

	public function testParamsNormalize()
	{
		$params = GAnalyticsEcommerce::params('purchase', [
			'transaction_id' => 'T-1',
			'currency' => ' usd ',
			'value' => '19.98',
			'tax' => 1,
			'shipping' => '4.5',
			'items' => ['a' => new GAnalyticsItem(['ItemId' => 'SKU-1', 'Price' => 9.99, 'Quantity' => 2]), ['item_name' => 'Gift wrap', 'price' => '0', 'quantity' => '1', 'index' => 2.0]],
		]);
		self::assertSame([
			'transaction_id' => 'T-1',
			'currency' => 'USD',
			'value' => 19.98,
			'tax' => 1.0,
			'shipping' => 4.5,
			'items' => [
				['item_id' => 'SKU-1', 'price' => 9.99, 'quantity' => 2],
				['item_name' => 'Gift wrap', 'price' => 0.0, 'quantity' => 1, 'index' => 2],
			],
		], $params);
	}

	public function testItemsAreOptionalForRefundsAndPromotions()
	{
		self::assertSame(['transaction_id' => 'T-1'], GAnalyticsEcommerce::params('refund', ['transaction_id' => 'T-1', 'items' => []]));
		self::assertSame(['promotion_id' => 'P'], GAnalyticsEcommerce::params('view_promotion', ['promotion_id' => 'P']));
		self::assertSame(['items' => [['item_id' => 'x']]], GAnalyticsEcommerce::params('select_promotion', ['items' => [['item_id' => 'x']]]));
	}

	public function testRules()
	{
		$item = ['item_id' => 'SKU-1'];
		$this->refused('checkout', [], 'ganalytics_ecommerce_event_invalid');
		$this->refused('add_to_cart', [], reason: 'items are required');
		$this->refused('add_to_cart', ['items' => 'SKU-1'], reason: 'items must be a list');
		$this->refused('add_to_cart', ['items' => \array_fill(0, 201, $item)], reason: 'at most 200 items');
		$this->refused('add_to_cart', ['items' => [$item], 'value' => 5], reason: 'value needs a currency');
		$this->refused('add_to_cart', ['items' => [$item], 'currency' => 'dollars'], reason: 'three-letter');
		$this->refused('add_to_cart', ['items' => [$item], 'currency' => 'USD', 'value' => 'lots'], reason: 'value must be a number');
		$this->refused('purchase', ['items' => [$item]], reason: 'transaction_id is required');
		$this->refused('refund', ['transaction_id' => ' '], reason: 'transaction_id is required');
		$this->refused('view_item', ['items' => ['SKU-1']], reason: 'must be a GAnalyticsItem or an array');
		$this->refused('view_item', ['items' => [['price' => 1]]], reason: 'item_id or an item_name');
		$this->refused('view_item', ['items' => [['item_id' => 'x', 'quantity' => 1.5]]], reason: 'item quantity must be an integer');
		$this->refused('view_item', ['items' => [['item_id' => 'x', 'price' => 'free']]], reason: 'item price must be a number');
		self::assertSame(['items' => [$item], 'currency' => 'EUR'], GAnalyticsEcommerce::params('view_item', ['items' => [$item], 'currency' => 'eur']), 'a currency alone is allowed');
	}

	public function testModuleTracksAndSends()
	{
		$module = $this->module();
		$item = new GAnalyticsItem(['ItemId' => 'SKU-1', 'Price' => 9.99]);
		$module->trackEcommerce('view_item', ['currency' => 'usd', 'value' => 9.99, 'items' => [$item]], true);
		self::assertSame([['event', 'view_item', ['currency' => 'USD', 'value' => 9.99, 'items' => [['item_id' => 'SKU-1', 'price' => 9.99]]]]], $module->deferred());

		self::assertTrue($module->sendEcommerce('refund', ['transaction_id' => 'T-1'], '1.2'));
		$payload = $module->protocol->lastPayload();
		self::assertSame('1.2', $payload['client_id']);
		self::assertSame([['name' => 'refund', 'params' => ['transaction_id' => 'T-1']]], \array_map(fn ($e) => \array_diff_key($e, ['timestamp_micros' => 1]), $payload['events']));

		$this->expectException(TInvalidDataValueException::class);
		$module->trackEcommerce('purchase', ['items' => [$item]]);
	}

	public function testPageBehaviorTracks()
	{
		$module = $this->module();
		$behavior = new GAnalyticsPageBehavior($module);
		$behavior->trackEcommerce(new TPage(), 'add_to_cart', ['items' => [['item_id' => 'SKU-1']]], true);
		self::assertSame([['event', 'add_to_cart', ['items' => [['item_id' => 'SKU-1']]]]], $module->deferred());
	}
}
