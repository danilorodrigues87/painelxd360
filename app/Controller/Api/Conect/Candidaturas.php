<?php

namespace App\Controller\Api\Conect;

use App\Common\Helpers\ConectApiMapper;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\CjCandidatura;
use App\Model\Entity\CjNotificacao;
use App\Model\Entity\CjVaga;

class Candidaturas {

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function listar($request): array {
		$candidato = $request->candidato ?? null;
		if (!$candidato instanceof CjCandidato) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (!CjCandidatura::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		$rows = CjCandidatura::queryLista(['id_candidato' => (int)$candidato->id, 'limit' => 100]);
		$items = array_map([ConectApiMapper::class, 'candidatura'], $rows);
		return self::respond(['items' => $items, 'sqlOk' => true]);
	}

	public static function criar($request): array {
		$user = $request->user ?? null;
		$candidato = $request->candidato ?? null;
		if (!$user || !$candidato instanceof CjCandidato) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (!CjCandidatura::tabelaExiste() || !CjVaga::tabelaExiste()) {
			return self::respond(['message' => 'Módulo não instalado.'], 503);
		}

		$post = $request->getPostVars() ?: [];
		$vagaId = (int)($post['vagaId'] ?? $post['vaga_id'] ?? 0);
		$mensagem = trim((string)($post['mensagem'] ?? $post['mensagemCandidato'] ?? ''));

		if ($vagaId <= 0) {
			return self::respond(['message' => 'Informe a vaga.'], 400);
		}

		$vaga = CjVaga::getById($vagaId);
		if (!$vaga || ($vaga['status'] ?? '') !== 'publicada') {
			return self::respond(['message' => 'Vaga não disponível para candidatura.'], 404);
		}

		$existente = CjCandidatura::getByVagaECandidato($vagaId, (int)$candidato->id);
		if ($existente) {
			return self::respond([
				'message'     => 'Você já se candidatou a esta vaga.',
				'candidatura' => ConectApiMapper::candidatura($existente),
			], 409);
		}

		$candidaturaId = CjCandidatura::inserir([
			'id_vaga'            => $vagaId,
			'id_candidato'       => (int)$candidato->id,
			'status'             => 'enviada',
			'mensagem_candidato' => $mensagem !== '' ? $mensagem : null,
		]);

		if (!$candidaturaId) {
			return self::respond(['message' => 'Não foi possível registrar a candidatura.'], 500);
		}

		$row = CjCandidatura::getById($candidaturaId);
		$tituloVaga = (string)($vaga['titulo'] ?? 'vaga');

		if (CjNotificacao::tabelaExiste()) {
			CjNotificacao::inserir([
				'id_usuario' => (int)$user->id,
				'tipo'       => 'candidatura',
				'titulo'     => 'Candidatura enviada',
				'mensagem'   => 'Sua candidatura para "'.$tituloVaga.'" foi registrada com sucesso.',
				'link'       => '/candidato',
			]);
		}

		$empresaId = (int)($vaga['id_empresa'] ?? 0);
		if ($empresaId > 0 && CjNotificacao::tabelaExiste()) {
			$empresa = \App\Model\Entity\CjEmpresa::getById($empresaId);
			if ($empresa && (int)($empresa->id_usuario ?? 0) > 0) {
				CjNotificacao::inserir([
					'id_usuario' => (int)$empresa->id_usuario,
					'tipo'       => 'candidatura',
					'titulo'     => 'Nova candidatura',
					'mensagem'   => ($candidato->nome ?? 'Candidato').' se candidatou à vaga "'.$tituloVaga.'".',
					'link'       => '/empresa',
				]);
			}
		}

		return self::respond([
			'message'     => 'Candidatura enviada com sucesso!',
			'candidatura' => $row ? ConectApiMapper::candidatura($row) : null,
		], 201);
	}
}
