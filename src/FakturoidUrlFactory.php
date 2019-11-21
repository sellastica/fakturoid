<?php
namespace Sellastica\Fakturoid\Service;

class FakturoidUrlFactory
{
	/** @var string */
	private const BASE_URL = 'https://app.fakturoid.cz/tomaskrejci3';


	/**
	 * @param \Sellastica\Localization\Model\Currency|null $currency
	 * @param int|null $subjectId
	 * @return string
	 */
	public static function proformaNew(
		\Sellastica\Localization\Model\Currency $currency = null,
		int $subjectId = null
	): string
	{
		if ($currency
			&& $currency->getCode() === 'EUR') {
			$url = new \Nette\Http\Url(self::BASE_URL . '/invoices/new_from_generator');
			$url->setQueryParameter('generator_id', 89055);
		} else {
			$url = new \Nette\Http\Url(self::BASE_URL . '/invoices/new');
		}

		if ($subjectId) {
			$url->setQueryParameter('subject_id', $subjectId);
		}

		return $url->getAbsoluteUrl();
	}

	/**
	 * @param int $id
	 * @return string
	 */
	public static function proforma(int $id): string
	{
		return self::BASE_URL . '/invoices/' . $id;
	}

	/**
	 * @param int $id
	 * @return string
	 */
	public static function sendProforma(int $id): string
	{
		return self::proforma($id) . '/message/new';
	}

	/**
	 * @param int $subjectId
	 * @return string
	 */
	public static function subject(int $subjectId): string
	{
		return self::BASE_URL . '/subjects/' . $subjectId;
	}
}