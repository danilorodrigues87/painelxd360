<?php

namespace App\Controller\Api\ConectEmpresa;

use App\Common\Helpers\ConectApiMapper;
use App\Common\Helpers\ConectCandidatoFormacaoHelper;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\CjCandidatoFormacao;
use App\Model\Entity\CjCandidatoHabilidade;
use App\Model\Entity\CjEmpresa;

class Talentos {

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function buscar($request): array {
		$empresa = $request->empresa ?? null;
		if (!$empresa instanceof CjEmpresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (($empresa->status ?? '') !== 'aprovada') {
			return self::respond(['message' => 'Empresa aguardando aprovação.'], 403);
		}
		if (!CjCandidato::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}

		$q = $request->getQueryParams();
		$filtros = [];
		if (!empty($q['q'])) {
			$filtros['q'] = (string)$q['q'];
		}
		if (!empty($q['habilidade'])) {
			$filtros['habilidade'] = (string)$q['habilidade'];
		}
		if (!empty($q['cidadeId']) || !empty($q['cidade_id'])) {
			$filtros['cidade_id'] = (int)($q['cidadeId'] ?? $q['cidade_id']);
		}
		if (!empty($q['uf'])) {
			$filtros['uf'] = (string)$q['uf'];
		}

		$rows = CjCandidato::buscarParaEmpresa($filtros, 80);
		$items = [];
		foreach ($rows as $row) {
			$id = (int)($row['id'] ?? 0);
			$hab = CjCandidatoHabilidade::listarPorCandidato($id);
			$formacao = ConectCandidatoFormacaoHelper::listarParaApi($id);
			$temSelo = CjCandidatoFormacao::temSeloCertificado($id);
			$items[] = ConectApiMapper::candidatoPerfil($row, $hab, $formacao, $temSelo);
		}

		return self::respond(['items' => $items, 'sqlOk' => true]);
	}
}
