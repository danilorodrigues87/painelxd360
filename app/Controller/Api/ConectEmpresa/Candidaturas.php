<?php

namespace App\Controller\Api\ConectEmpresa;

use App\Common\Helpers\ConectApiMapper;
use App\Common\Helpers\ConectCandidatoFormacaoHelper;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\CjCandidatoFormacao;
use App\Model\Entity\CjCandidatoHabilidade;
use App\Model\Entity\CjCandidatura;
use App\Model\Entity\CjEmpresa;
use App\Model\Entity\CjNotificacao;
use App\Model\Entity\CjVaga;
use App\Model\Entity\User;

class Candidaturas {

	private const STATUS_VALIDOS = [
		'enviada', 'visualizada', 'em_analise', 'pre_selecionado', 'contratado', 'recusado',
	];

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function listar($request): array {
		$empresa = $request->empresa ?? null;
		if (!$empresa instanceof CjEmpresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (!CjCandidatura::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}

		$q = $request->getQueryParams();
		$filtros = [
			'id_empresa' => (int)$empresa->id,
			'limit'      => 100,
		];
		if (!empty($q['vagaId']) || !empty($q['vaga_id'])) {
			$filtros['id_vaga'] = (int)($q['vagaId'] ?? $q['vaga_id']);
		}
		if (!empty($q['status'])) {
			$filtros['status'] = (string)$q['status'];
		}

		$rows = CjCandidatura::queryLista($filtros);
		$items = array_map([ConectApiMapper::class, 'candidaturaEmpresa'], $rows);
		return self::respond(['items' => $items, 'sqlOk' => true]);
	}

	public static function detalhe($request, int $id): array {
		$empresa = $request->empresa ?? null;
		if (!$empresa instanceof CjEmpresa || $id <= 0) {
			return self::respond(['message' => 'Inválido.'], 400);
		}
		if (!CjCandidatura::tabelaExiste()) {
			return self::respond(['message' => 'Módulo não instalado.'], 503);
		}

		$row = CjCandidatura::getById($id);
		if (!$row) {
			return self::respond(['message' => 'Candidatura não encontrada.'], 404);
		}

		$vaga = CjVaga::getById((int)$row['id_vaga']);
		if (!$vaga || (int)$vaga['id_empresa'] !== (int)$empresa->id) {
			return self::respond(['message' => 'Candidatura não encontrada.'], 404);
		}

		if (($row['status'] ?? '') === 'enviada') {
			CjCandidatura::atualizar($id, ['status' => 'visualizada']);
			$row = CjCandidatura::getById($id) ?? $row;
		}

		$candidato = CjCandidato::getByIdEnriched((int)$row['id_candidato']);
		$candidatoId = $candidato ? (int)($candidato['id'] ?? 0) : 0;
		$habilidades = $candidatoId > 0 ? CjCandidatoHabilidade::listarPorCandidato($candidatoId) : [];
		$formacao = $candidatoId > 0 ? ConectCandidatoFormacaoHelper::listarParaApi($candidatoId) : [];
		$temSelo = $candidatoId > 0 ? CjCandidatoFormacao::temSeloCertificado($candidatoId) : false;

		return self::respond([
			'candidatura' => ConectApiMapper::candidaturaEmpresa($row),
			'candidato'   => $candidato
				? ConectApiMapper::candidatoPerfil($candidato, $habilidades, $formacao, $temSelo)
				: null,
		]);
	}

	public static function atualizarStatus($request, int $id): array {
		$user = $request->user ?? null;
		$empresa = $request->empresa ?? null;
		if (!$user instanceof User || !$empresa instanceof CjEmpresa || $id <= 0) {
			return self::respond(['message' => 'Inválido.'], 400);
		}
		if (!CjCandidatura::tabelaExiste()) {
			return self::respond(['message' => 'Módulo não instalado.'], 503);
		}

		$row = CjCandidatura::getById($id);
		if (!$row) {
			return self::respond(['message' => 'Candidatura não encontrada.'], 404);
		}
		$vaga = CjVaga::getById((int)$row['id_vaga']);
		if (!$vaga || (int)$vaga['id_empresa'] !== (int)$empresa->id) {
			return self::respond(['message' => 'Candidatura não encontrada.'], 404);
		}

		$post = $request->getPostVars() ?: [];
		$status = (string)($post['status'] ?? '');
		if (!in_array($status, self::STATUS_VALIDOS, true)) {
			return self::respond(['message' => 'Status inválido.'], 400);
		}

		$dados = ['status' => $status];
		if (array_key_exists('mensagemEmpresa', $post) || array_key_exists('mensagem_empresa', $post)) {
			$msg = trim((string)($post['mensagemEmpresa'] ?? $post['mensagem_empresa'] ?? ''));
			$dados['mensagem_empresa'] = $msg !== '' ? $msg : null;
		}
		if ($status === 'contratado') {
			$dados['contratado_em'] = date('Y-m-d');
		}

		CjCandidatura::atualizar($id, $dados);
		$rowAtual = CjCandidatura::getById($id);

		$candidato = CjCandidato::getById((int)($row['id_candidato'] ?? 0));
		if ($candidato && (int)($candidato->id_usuario ?? 0) > 0 && CjNotificacao::tabelaExiste()) {
			$labels = [
				'em_analise'      => 'Em análise',
				'pre_selecionado' => 'Pré-selecionado',
				'contratado'      => 'Contratado',
				'recusado'        => 'Recusado',
			];
			if (isset($labels[$status])) {
				CjNotificacao::inserir([
					'id_usuario' => (int)$candidato->id_usuario,
					'tipo'       => 'candidatura',
					'titulo'     => 'Atualização da candidatura',
					'mensagem'   => 'Sua candidatura para "'.($vaga['titulo'] ?? 'vaga').'" está: '.$labels[$status].'.',
					'link'       => '/candidato',
				]);
			}
		}

		return self::respond([
			'message'     => 'Status atualizado.',
			'candidatura' => $rowAtual ? ConectApiMapper::candidaturaEmpresa($rowAtual) : null,
		]);
	}
}
