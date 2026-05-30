<?php
namespace EnterpriseForms;

interface EP_Payment_Gateway {
	/**
	 * Prepare credentials and gateway SDK clients.
	 */
	public function initialize(): void;

	/**
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	public function create_intent( int $amount, string $currency, array $meta ): array;

	/**
	 * @return array<string, mixed>
	 */
	public function verify_payment( string $transaction_id ): array;
}