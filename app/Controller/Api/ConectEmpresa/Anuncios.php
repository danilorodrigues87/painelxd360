<?php

namespace App\Controller\Api\ConectEmpresa;

use App\Common\Helpers\BrandingHelper;
use App\Common\Helpers\ConectAnuncioAssinaturaService;
use App\Common\Helpers\ConectAnuncioHelper;
use App\Model\Entity\CjAnuncio;
use App\Model\Entity\CjAnuncioConfig;
use App\Model\Entity\CjAnuncioEvento;
use App\Model\Entity\CjEmpresa;

class Anuncios {

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	private static function empresa($request): ?CjEmpresa {
		$e = $request->empresa ?? null;
		return $e instanceof CjEmpresa ? $e : null;
	}

	public static function config($request): array {
		if (!CjAnuncio::tabelaExiste()) {
			return self::respond(['message' => 'Módulo de anúncios não instalado.'], 503);
		}
		$empresa = self::empresa($request);
		if (!$empresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		$config = CjAnuncioConfig::get();
		$slots = [];
		foreach (ConectAnuncioHelper::SLOTS as $key => $label) {
			if (in_array($key, $config['slots_habilitados'] ?? [], true)) {
				$slots[$key] = $label;
			}
		}
		$assinatura = ConectAnuncioAssinaturaService::resumoEmpresa((int)$empresa->id);
		if (ConectAnuncioAssinaturaService::moduloAtivo()) {
			$limite = (int)($assinatura['limiteAnuncios'] ?? 0);
		} else {
			$limite = (int)($config['max_anuncios_por_empresa'] ?? 3);
		}
		return self::respond([
			'precoMinimoMensal'     => (float)($config['preco_minimo_mensal'] ?? 99),
			'maxAnunciosPorEmpresa' => $limite,
			'slots'                 => $slots,
			'slotDimensoes'         => ConectAnuncioHelper::SLOT_DIMENSOES,
			'usados'                => (int)($assinatura['usados'] ?? CjAnuncio::contarOcupandoPlano((int)$empresa->id)),
			'assinatura'            => $assinatura,
			'requerAprovacaoMaster' => !empty($config['requer_aprovacao_master']),
		]);
	}

	public static function listar($request): array {
		$empresa = self::empresa($request);
		if (!$empresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (!CjAnuncio::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		$rows = CjAnuncio::listarPorEmpresa((int)$empresa->id);
		$ids = array_map(static fn($r) => (int)($r['id'] ?? 0), $rows);
		$metricas = CjAnuncioEvento::resumoPorAnuncios($ids);
		$items = [];
		foreach ($rows as $row) {
			$id = (int)($row['id'] ?? 0);
			$items[] = ConectAnuncioHelper::mapAdmin($row, $metricas[$id] ?? null);
		}
		return self::respond(['items' => $items, 'sqlOk' => true]);
	}

	public static function criar($request): array {
		$empresa = self::empresa($request);
		if (!$empresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (($empresa->status ?? '') !== 'aprovada') {
			return self::respond(['message' => 'Empresa aguardando aprovação.'], 403);
		}
		if (!CjAnuncio::tabelaExiste()) {
			return self::respond(['message' => 'Execute database/cj_anuncios.sql.'], 503);
		}

		$config = CjAnuncioConfig::get();
		if (!ConectAnuncioAssinaturaService::podeCriarAnuncio((int)$empresa->id)) {
			if (ConectAnuncioAssinaturaService::moduloAtivo() && !ConectAnuncioAssinaturaService::temAssinaturaAtiva((int)$empresa->id)) {
				return self::respond([
					'message' => 'Assine um plano de anúncios para publicar.',
					'code'    => 'assinatura_requerida',
				], 402);
			}
			$max = ConectAnuncioAssinaturaService::limiteAnuncios((int)$empresa->id);
			if ($max <= 0 && !ConectAnuncioAssinaturaService::moduloAtivo()) {
				$max = (int)($config['max_anuncios_por_empresa'] ?? 3);
			}
			return self::respond([
				'message' => 'Limite de '.$max.' anúncio(s) ativo(s)/pendente(s) atingido no seu plano.',
				'code'    => 'limite_anuncios',
			], 400);
		}

		$post = $request->getPostVars() ?: [];
		$files = $request->getFileVars();
		$upload = BrandingHelper::processarUploadConectAnuncioDetalhe($files['imagem'] ?? null);
		if (!empty($upload['error'])) {
			return self::respond(['message' => $upload['error']], 400);
		}
		$imagem = $upload['filename'];
		$geo = ConectAnuncioHelper::geoEmpresa($empresa);

		$valid = ConectAnuncioHelper::validarPayload(array_merge($post, [
			'imagem_arquivo' => $imagem,
			'uf'             => $geo['uf'],
			'cidade_id'      => $geo['cidade_id'],
		]));
		if (empty($valid['ok'])) {
			return self::respond(['message' => $valid['message'] ?? 'Dados inválidos.'], 400);
		}

		$dados = $valid['data'];
		$dados['publisher'] = 'conecta_jovem';
		$dados['owner_tipo'] = 'empresa';
		$dados['id_empresa'] = (int)$empresa->id;
		$dados['imagem_arquivo'] = $imagem;
		$dados['status'] = ConectAnuncioHelper::statusInicialEmpresa();
		$dados['uf'] = $geo['uf'];
		$dados['cidade_id'] = $geo['cidade_id'];

		$id = CjAnuncio::inserir($dados);
		$row = CjAnuncio::getById($id);
		$msg = $dados['status'] === 'pendente'
			? 'Anúncio enviado para aprovação.'
			: 'Anúncio publicado.';

		return self::respond([
			'message' => $msg,
			'anuncio' => $row ? ConectAnuncioHelper::mapAdmin($row) : null,
		], 201);
	}

	public static function atualizar($request, int $id): array {
		$empresa = self::empresa($request);
		if (!$empresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		$row = CjAnuncio::getById($id);
		if (!$row || (int)($row['id_empresa'] ?? 0) !== (int)$empresa->id) {
			return self::respond(['message' => 'Anúncio não encontrado.'], 404);
		}

		$post = $request->getPostVars() ?: [];
		$acao = (string)($post['acao'] ?? '');
		if ($acao === 'pausar' && in_array($row['status'], ['ativo', 'pendente'], true)) {
			CjAnuncio::atualizar($id, ['status' => 'pausado']);
			$atual = CjAnuncio::getById($id);
			return self::respond([
				'message' => 'Anúncio pausado.',
				'anuncio' => $atual ? ConectAnuncioHelper::mapAdmin($atual) : null,
			]);
		}
		if ($acao === 'retomar' && ($row['status'] ?? '') === 'pausado') {
			$limite = ConectAnuncioAssinaturaService::limiteAnuncios((int)$empresa->id);
			if ($limite > 0 && ConectAnuncioAssinaturaService::contarAnunciosPlano((int)$empresa->id) >= $limite) {
				return self::respond([
					'message' => 'Limite de '.$limite.' anúncio(s) ativo(s)/pendente(s) atingido. Pause ou exclua outro anúncio.',
					'code'    => 'limite_anuncios',
				], 400);
			}
			CjAnuncio::atualizar($id, ['status' => ConectAnuncioHelper::statusInicialEmpresa()]);
			$atual = CjAnuncio::getById($id);
			return self::respond([
				'message' => 'Anúncio retomado.',
				'anuncio' => $atual ? ConectAnuncioHelper::mapAdmin($atual) : null,
			]);
		}

		$files = $request->getFileVars();
		$upload = BrandingHelper::processarUploadConectAnuncioDetalhe(
			$files['imagem'] ?? null,
			$row['imagem_arquivo'] ?? null
		);
		if (!empty($upload['error'])) {
			return self::respond(['message' => $upload['error']], 400);
		}
		$imagem = $upload['filename'];

		$valid = ConectAnuncioHelper::validarPayload(array_merge($post, [
			'imagem_arquivo' => $imagem ?: ($row['imagem_arquivo'] ?? ''),
		]), !$imagem && empty($row['imagem_arquivo']));
		if (empty($valid['ok'])) {
			return self::respond(['message' => $valid['message'] ?? 'Dados inválidos.'], 400);
		}

		$dados = $valid['data'];
		unset($dados['uf'], $dados['cidade_id']);
		if ($imagem) {
			$dados['imagem_arquivo'] = $imagem;
		}

		CjAnuncio::atualizar($id, $dados);
		$atual = CjAnuncio::getById($id);
		return self::respond([
			'message' => 'Anúncio atualizado.',
			'anuncio' => $atual ? ConectAnuncioHelper::mapAdmin($atual) : null,
		]);
	}

	public static function excluir($request, int $id): array {
		$empresa = self::empresa($request);
		if (!$empresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		$row = CjAnuncio::getById($id);
		if (!$row || (int)($row['id_empresa'] ?? 0) !== (int)$empresa->id) {
			return self::respond(['message' => 'Anúncio não encontrado.'], 404);
		}
		CjAnuncio::excluir($id);
		return self::respond(['message' => 'Anúncio excluído.']);
	}
}
