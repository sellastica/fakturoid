<?php
namespace Sellastica\Fakturoid\Service;

class FakturoidService
{
	private const REQUIRED_TAG = 'Povinná';

	/** @var \Fakturoid\Client */
	private $fakturoid;
	/** @var \Sellastica\Crm\Entity\Invoice\Service\InvoiceService */
	private $invoiceService;
	/** @var \Sellastica\Crm\Entity\TariffHistory\Service\TariffHistoryService */
	private $tariffHistoryService;
	/** @var array */
	private $parameters;
	/** @var \Nette\Localization\ITranslator */
	private $translator;


	/**
	 * @param array $parameters
	 * @param \Fakturoid\Client $fakturoid
	 * @param \Sellastica\Crm\Entity\Invoice\Service\InvoiceService $invoiceService
	 * @param \Sellastica\Crm\Entity\TariffHistory\Service\TariffHistoryService $tariffHistoryService
	 * @param \Nette\Localization\ITranslator $translator
	 */
	public function __construct(
		array $parameters,
		\Fakturoid\Client $fakturoid,
		\Sellastica\Crm\Entity\Invoice\Service\InvoiceService $invoiceService,
		\Sellastica\Crm\Entity\TariffHistory\Service\TariffHistoryService $tariffHistoryService,
		\Nette\Localization\ITranslator $translator
	)
	{
		$this->fakturoid = $fakturoid;
		$this->invoiceService = $invoiceService;
		$this->tariffHistoryService = $tariffHistoryService;
		$this->parameters = $parameters;
		$this->translator = $translator;
	}

	/**
	 * @param \Sellastica\Crm\Entity\Invoice\Entity\Invoice $invoice
	 */
	public function cancelInvoice(\Sellastica\Crm\Entity\Invoice\Entity\Invoice $invoice): void
	{
		$this->fakturoid->fireInvoice($invoice->getExternalId(), 'cancel');
		$invoice->setCancelled(true);
	}

	/**
	 * @param \Sellastica\Crm\Entity\Invoice\Entity\Invoice $invoice
	 * @param \stdClass $fakturoidInvoice
	 */
	public function updateLocalInvoice(
		\Sellastica\Crm\Entity\Invoice\Entity\Invoice $invoice,
		\stdClass $fakturoidInvoice
	): void
	{
		$invoice->setProforma((bool)$fakturoidInvoice->proforma);
		$invoice->setCode($fakturoidInvoice->number);
		$invoice->setVarSymbol($fakturoidInvoice->variable_symbol);
		$invoice->setCreated(new \DateTime($fakturoidInvoice->issued_on));
		$invoice->setDueDate(new \DateTime($fakturoidInvoice->due_on));
		$invoice->setPaymentDate($fakturoidInvoice->paid_at ? new \DateTime($fakturoidInvoice->paid_at) : null);
		$invoice->setCancelled((bool)$fakturoidInvoice->cancelled_at);
		$invoice->setPrice(\Sellastica\Price\Price::sumPrice(
			$fakturoidInvoice->total,
			$fakturoidInvoice->total - $fakturoidInvoice->subtotal,
			\Sellastica\Localization\Model\Currency::from($fakturoidInvoice->currency)
		));
		$invoice->setExternalUrl($fakturoidInvoice->public_html_url);
		$invoice->setPriceToPay($fakturoidInvoice->remaining_amount);
		$invoice->setPaidAmount(
			//fakturoid used home currency for paid amount even if invoice is in foreign currency
			$fakturoidInvoice->paid_amount > $invoice->getPriceToPay()
				? $invoice->getPriceToPay()
				: $fakturoidInvoice->paid_amount
		);
		$invoice->setExchangeRate($fakturoidInvoice->exchange_rate);
		$invoice->setSent($fakturoidInvoice->sent_at ? new \DateTime($fakturoidInvoice->sent_at) : null);
		$invoice->setMustPay(in_array(self::REQUIRED_TAG, $fakturoidInvoice->tags));
	}

	/**
	 * @param \Sellastica\Crm\Entity\Invoice\Entity\Invoice $invoice
	 * @param \stdClass $fakturoidInvoice
	 * @return \Fakturoid\Response Fakturoid update response
	 */
	public function updateFakturoidInvoice(
		\Sellastica\Crm\Entity\Invoice\Entity\Invoice $invoice,
		\stdClass $fakturoidInvoice
	): \Fakturoid\Response
	{
		$tariffHistory = $this->tariffHistoryService->findBy(['invoiceId' => $invoice->getId()]);
		$data = \Sellastica\Utils\Arrays::filterNulls([
			'currency' => $invoice->getProject()->getCurrency()->getCode(),
			'round_total' => true,
		]);
		if ($invoice->getProject()->getCurrency()->getCode() === 'EUR') {
			$data['exchange_rate'] = 26;
			$data['bank_account'] = $this->parameters['bank_accounts']['EUR']['bank_account'];
			$data['iban'] = $this->parameters['bank_accounts']['EUR']['iban'];
			$data['swift_bic'] = $this->parameters['bank_accounts']['EUR']['swift_bic'];

			if ($invoice->getProject()->isVatPayer()) {
				$data['transferred_tax_liability'] = true;
				$data['supply_code'] = 3;
			} else {
				$data['transferred_tax_liability'] = false;
			}
		}

		foreach ($tariffHistory as $tariffHistoryItem) {
			$tariffPrice = $tariffHistoryItem->getTariff()->getTariffPrice($invoice->getProject()->getCurrency());
			$unitPrice = $tariffHistoryItem->getAccountingPeriod()->isAnnual()
				? $tariffPrice->getAnnual()->getWithoutTax()
				: $tariffPrice->getMonthly()->getWithoutTax();
			//calculate project percent discount
			if ($invoice->getProject()->getPercentDiscount()) {
				$unitPrice = round($unitPrice * (1 - $invoice->getProject()->getPercentDiscount() / 100));
			}

			$data['lines'][] = [
				'name' => $tariffHistoryItem->getTitle(),
				'quantity' => 1,
				'unit_price' => $unitPrice,
				'vat_rate' => $invoice->getProject()->getCurrency()->getCode() === 'EUR' && $invoice->getProject()->isVatPayer()
					? 0
					: 21,
			];
		}

		//delete old invoice lines
		foreach ($fakturoidInvoice->lines as $line) {
			$data['lines'][] = array_merge((array)$line, [
				'_destroy' => true,
			]);
		}

		return $this->fakturoid->updateInvoice($invoice->getExternalId(), $data);
	}

	/**
	 * @param \Sellastica\Project\Entity\Project $project
	 * @param \stdClass $fakturoidInvoice
	 */
	public function createLocalInvoice(
		\Sellastica\Project\Entity\Project $project,
		\stdClass $fakturoidInvoice
	): void
	{
		$invoice = $this->invoiceService->create(
			$project->getId(),
			$fakturoidInvoice->number,
			$fakturoidInvoice->variable_symbol,
			new \DateTime($fakturoidInvoice->due_on),
			\Sellastica\Price\Price::sumPrice(
				$fakturoidInvoice->total,
				$fakturoidInvoice->total - $fakturoidInvoice->subtotal,
				\Sellastica\Localization\Model\Currency::from($fakturoidInvoice->currency)
			),
			$fakturoidInvoice->id
		);
		$this->updateLocalInvoice(
			$invoice,
			$fakturoidInvoice
		);
	}

	/**
	 * @param \Sellastica\Project\Entity\Project $project
	 * @param \Sellastica\Crm\Entity\TariffHistory\Entity\TariffHistory $tariffHistory
	 * @param \Sellastica\Localization\Model\Currency $currency
	 * @param float $unitPrice
	 * @return array
	 */
	public function getFakturoidInvoiceData(
		\Sellastica\Project\Entity\Project $project,
		\Sellastica\Crm\Entity\TariffHistory\Entity\TariffHistory $tariffHistory,
		\Sellastica\Localization\Model\Currency $currency,
		float $unitPrice
	): array
	{
		$billingAddress = $project->getBillingAddress();
		$data = \Sellastica\Utils\Arrays::filterNulls([
			'proforma' => true,
			'partial_proforma' => false,
			'variable_symbol' => $project->getId(),
			'client_name' => $billingAddress && $billingAddress->getCompanyOrFullName()
				? $billingAddress->getCompanyOrFullName()
				: $project->getShortTitle(),
			'client_street' => $billingAddress ? $billingAddress->getStreet() : null,
			'client_city' => $billingAddress ? $billingAddress->getCity() : null,
			'client_zip' => $billingAddress ? $billingAddress->getZip() : null,
			'client_country' => $billingAddress && $billingAddress->getCountry()
				? $billingAddress->getCountry()->getCode()
				: null,
			'client_registration_no' => $billingAddress ? $billingAddress->getCin() : null,
			'client_vat_no' => $billingAddress
				? $this->getTin($billingAddress)
				: null,
			'subject_id' => $project->getExternalId(),
			'status' => 'open',
			'payment_method' => 'bank',
			'round_total' => true,
			'currency' => $currency->getCode(),
			'tags' => ['Povinná'],
			'note' => $this->translator->translate('admin.accounting.we_invoice_you_for_project', ['project' => $project->getShortTitle()]),
			'lines' => [[
				'name' => $tariffHistory->getTitle(),
				'quantity' => 1,
				'unit_price' => $unitPrice,
				'vat_rate' => $project->isVatPayer() && $project->getCurrency()->getCode() === 'EUR'
					? 0
					: 21,
			]],
		]);

		//bank account
		if ($project->getCurrency()->getCode() === 'EUR') {
			$data['bank_account'] = $this->parameters['bank_accounts']['EUR']['bank_account'];
			$data['iban'] = $this->parameters['bank_accounts']['EUR']['iban'];
			$data['swift_bic'] = $this->parameters['bank_accounts']['EUR']['swift_bic'];

			//transferred tax
			if ($project->isVatPayer()) {
				$data['supply_code'] = 3;
				$data['transferred_tax_liability'] = true;
			}
		}

		return $data;
	}

	/**
	 * @param \Sellastica\Identity\Model\BillingAddress $billingAddress
	 * @return string|null
	 */
	private function getTin(\Sellastica\Identity\Model\BillingAddress $billingAddress): ?string
	{
		if (!$billingAddress->getTin()) {
			return null;
		} elseif (!\Nette\Utils\Strings::startsWith($billingAddress->getTin(), 'CZ')
			&& !\Nette\Utils\Strings::startsWith($billingAddress->getTin(), 'SK')) {
			return null;
		}

		return $billingAddress->getTin();
	}
}