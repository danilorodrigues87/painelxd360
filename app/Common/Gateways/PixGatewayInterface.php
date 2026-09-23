<?php

namespace App\Common\Gateways;

interface PixGatewayInterface {

	/**
	 * @param array{
	 *   valor:float|string,
	 *   descricao:string,
	 *   vencimento:string,
	 *   external_reference:string,
	 *   pagador_nome?:string,
	 *   pagador_cpf?:string,
	 *   pagador_email?:string
	 * } $dados
	 * @return array{id:string, copia_cola:string, qr_base64:?string}|null
	 */
	public function criarCobrancaPix(array $dados): ?array;
}
