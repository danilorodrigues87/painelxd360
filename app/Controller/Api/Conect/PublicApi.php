<?php

namespace App\Controller\Api\Conect;

use App\Common\Helpers\ConectApiMapper;
use App\Common\Helpers\ConectContactHelper;
use App\Model\Entity\CjDepoimento;
use App\Model\Entity\CjEmpresa;
use App\Model\Entity\CjPortalBranding;
use App\Model\Entity\CjVaga;
use App\Common\Helpers\ConectEnderecoHelper;
use App\Model\Entity\EstadoCidades;

class PublicApi {

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function branding($request): array {
		return self::respond([
			'branding' => ConectApiMapper::branding(CjPortalBranding::get()),
			'sqlOk'    => CjPortalBranding::tabelasExistem(),
		]);
	}

	public static function vagas($request): array {
		if (!CjVaga::tabelaExiste()) {
			return self::respond([
				'items'   => [],
				'sqlOk'   => false,
				'message' => 'Execute database/conect_jovem.sql no servidor.',
			]);
		}
		$q = $request->getQueryParams();
		$filtros = ['limit' => (int)($q['limit'] ?? 50)];
		if (!empty($q['cidade'])) {
			$filtros['cidade_id'] = (int)$q['cidade'];
		}
		if (!empty($q['empresa'])) {
			$filtros['empresa_id'] = (int)$q['empresa'];
		}
		if (!empty($q['tipo'])) {
			$filtros['tipo_vaga'] = (string)$q['tipo'];
		}
		if (!empty($q['q'])) {
			$filtros['q'] = (string)$q['q'];
		}
		$rows = CjVaga::queryLista($filtros);
		$items = array_map([ConectApiMapper::class, 'vaga'], $rows);
		return self::respond(['items' => $items, 'sqlOk' => true]);
	}

	public static function vagaDetalhe($request, string $slug): array {
		if (!CjVaga::tabelaExiste()) {
			return self::respond(['message' => 'Módulo não instalado.'], 503);
		}
		$row = CjVaga::getBySlug($slug);
		if (!$row) {
			return self::respond(['message' => 'Vaga não encontrada.'], 404);
		}
		CjVaga::incrementarViews((int)$row['id']);
		return self::respond(['vaga' => ConectApiMapper::vaga($row)]);
	}

	public static function empresas($request): array {
		if (!CjEmpresa::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		$q = $request->getQueryParams();
		$cidadeId = !empty($q['cidade']) ? (int)$q['cidade'] : null;
		$busca = !empty($q['q']) ? (string)$q['q'] : null;
		$rows = CjEmpresa::listarPublicas($cidadeId, 100, $busca);
		$items = array_map([ConectApiMapper::class, 'empresa'], $rows);
		return self::respond(['items' => $items, 'sqlOk' => true]);
	}

	public static function cidades($request): array {
		if (!CjVaga::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		return self::respond(['items' => CjVaga::cidadesComVagas(), 'sqlOk' => true]);
	}

	public static function estados($request): array {
		return self::respond(['items' => ConectEnderecoHelper::listarEstadosApi()]);
	}

	public static function cidadesPorEstado($request, int $estadoId): array {
		$rows = EstadoCidades::getCidades('estados_id = '.(int)$estadoId, 'nome ASC');
		$items = [];
		if ($rows) {
			while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
				$items[] = ['id' => (int)$r['id'], 'nome' => (string)$r['nome']];
			}
		}
		return self::respond(['items' => $items]);
	}

	public static function contato($request): array {
		$post = $request->getPostVars() ?: [];
		if (!empty($post['website'])) {
			return self::respond(['message' => 'Mensagem enviada com sucesso.']);
		}

		$result = ConectContactHelper::enviar(
			(string)($post['nome'] ?? ''),
			(string)($post['email'] ?? ''),
			(string)($post['assunto'] ?? ''),
			(string)($post['mensagem'] ?? ''),
			(string)($post['whatsapp'] ?? '')
		);

		if (!$result['ok']) {
			return self::respond(['message' => $result['message']], 400);
		}

		return self::respond(['message' => $result['message']]);
	}

	public static function depoimentos($request): array {
		if (!CjDepoimento::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		$rows = CjDepoimento::listarPublicos(12);
		$items = array_map([ConectApiMapper::class, 'depoimento'], $rows);
		return self::respond(['items' => $items, 'sqlOk' => true]);
	}
}
