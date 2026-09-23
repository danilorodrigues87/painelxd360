<?php

namespace App\Controller\Api\ConectEmpresa;

use App\Common\Helpers\ConectApiMapper;
use App\Model\Entity\CjEmpresa;
use App\Model\Entity\CjVaga;
use App\Model\Db\Database;

class Vagas {

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
		if (!CjVaga::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		$rows = CjVaga::queryLista([
			'empresa_id_internal' => (int)$empresa->id,
			'status_any'          => true,
			'limit'               => 100,
		]);
		$items = array_map([ConectApiMapper::class, 'vaga'], $rows);
		return self::respond(['items' => $items, 'sqlOk' => true]);
	}

	public static function criar($request): array {
		$empresa = $request->empresa ?? null;
		if (!$empresa instanceof CjEmpresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (($empresa->status ?? '') !== 'aprovada') {
			return self::respond(['message' => 'Empresa aguardando aprovação para publicar vagas.'], 403);
		}
		if (!CjVaga::tabelaExiste()) {
			return self::respond(['message' => 'Módulo não instalado.'], 503);
		}

		$post = $request->getPostVars() ?: [];
		$titulo = trim((string)($post['titulo'] ?? ''));
		$descricao = trim((string)($post['descricao'] ?? ''));
		if ($titulo === '' || $descricao === '') {
			return self::respond(['message' => 'Título e descrição são obrigatórios.'], 400);
		}

		$tipo = (string)($post['tipoVaga'] ?? $post['tipo_vaga'] ?? 'clt');
		if (!in_array($tipo, ['aprendiz', 'estagio', 'clt', 'freelance'], true)) {
			$tipo = 'clt';
		}
		$modalidade = (string)($post['modalidade'] ?? 'presencial');
		if (!in_array($modalidade, ['presencial', 'hibrido', 'remoto'], true)) {
			$modalidade = 'presencial';
		}

		$slugBase = ConectApiMapper::slugify($titulo);
		$slug = CjVaga::slugUnico($slugBase);
		$cidadeId = (int)($post['cidadeId'] ?? $post['cidade_id'] ?? 0);

		$id = (new Database('cj_vagas'))->insert([
			'id_empresa'  => (int)$empresa->id,
			'titulo'      => mb_substr($titulo, 0, 191),
			'slug'        => $slug,
			'tipo_vaga'   => $tipo,
			'descricao'   => $descricao,
			'requisitos'  => trim((string)($post['requisitos'] ?? '')),
			'cidade_id'   => $cidadeId > 0 ? $cidadeId : ($empresa->cidade_id ?: null),
			'uf'          => (string)($post['uf'] ?? $empresa->uf ?? ''),
			'modalidade'  => $modalidade,
			'status'      => 'pendente',
		]);

		if (!$id) {
			return self::respond(['message' => 'Erro ao salvar vaga.'], 500);
		}

		$row = CjVaga::getById((int)$id);
		return self::respond([
			'message' => 'Vaga enviada para moderação.',
			'vaga'    => $row ? ConectApiMapper::vaga($row) : null,
		], 201);
	}

	public static function atualizar($request, int $vagaId): array {
		$empresa = $request->empresa ?? null;
		if (!$empresa instanceof CjEmpresa || $vagaId <= 0) {
			return self::respond(['message' => 'Inválido.'], 400);
		}
		$row = CjVaga::getById($vagaId);
		if (!$row || (int)$row['id_empresa'] !== (int)$empresa->id) {
			return self::respond(['message' => 'Vaga não encontrada.'], 404);
		}
		if (in_array($row['status'] ?? '', ['encerrada'], true)) {
			return self::respond(['message' => 'Vaga encerrada não pode ser editada.'], 403);
		}

		$post = $request->getPostVars() ?: [];
		$titulo = trim((string)($post['titulo'] ?? $row['titulo'] ?? ''));
		$descricao = trim((string)($post['descricao'] ?? $row['descricao'] ?? ''));
		if ($titulo === '' || $descricao === '') {
			return self::respond(['message' => 'Título e descrição são obrigatórios.'], 400);
		}

		$tipo = (string)($post['tipoVaga'] ?? $post['tipo_vaga'] ?? $row['tipo_vaga'] ?? 'clt');
		if (!in_array($tipo, ['aprendiz', 'estagio', 'clt', 'freelance'], true)) {
			$tipo = (string)$row['tipo_vaga'];
		}
		$modalidade = (string)($post['modalidade'] ?? $row['modalidade'] ?? 'presencial');
		if (!in_array($modalidade, ['presencial', 'hibrido', 'remoto'], true)) {
			$modalidade = (string)$row['modalidade'];
		}
		$cidadeId = (int)($post['cidadeId'] ?? $post['cidade_id'] ?? $row['cidade_id'] ?? 0);

		$dados = [
			'titulo'     => mb_substr($titulo, 0, 191),
			'tipo_vaga'  => $tipo,
			'descricao'  => $descricao,
			'requisitos' => trim((string)($post['requisitos'] ?? $row['requisitos'] ?? '')),
			'cidade_id'  => $cidadeId > 0 ? $cidadeId : ($empresa->cidade_id ?: null),
			'uf'         => (string)($post['uf'] ?? $row['uf'] ?? $empresa->uf ?? ''),
			'modalidade' => $modalidade,
		];

		$statusAtual = (string)($row['status'] ?? '');
		if ($statusAtual === 'publicada' && (
			$dados['titulo'] !== ($row['titulo'] ?? '')
			|| $dados['descricao'] !== ($row['descricao'] ?? '')
		)) {
			$dados['status'] = 'pendente';
		}

		(new Database('cj_vagas'))->update('id = '.(int)$vagaId, $dados);
		$atual = CjVaga::getById($vagaId);
		return self::respond([
			'message' => ($dados['status'] ?? '') === 'pendente'
				? 'Vaga atualizada e reenviada para moderação.'
				: 'Vaga atualizada.',
			'vaga' => $atual ? ConectApiMapper::vaga($atual) : null,
		]);
	}

	public static function acao($request, int $vagaId): array {
		$empresa = $request->empresa ?? null;
		if (!$empresa instanceof CjEmpresa || $vagaId <= 0) {
			return self::respond(['message' => 'Inválido.'], 400);
		}
		$row = CjVaga::getById($vagaId);
		if (!$row || (int)$row['id_empresa'] !== (int)$empresa->id) {
			return self::respond(['message' => 'Vaga não encontrada.'], 404);
		}

		$post = $request->getPostVars() ?: [];
		$acao = strtolower(trim((string)($post['acao'] ?? '')));
		$status = (string)($row['status'] ?? '');
		$dados = [];

		switch ($acao) {
			case 'pausar':
				if (!in_array($status, ['publicada', 'pendente'], true)) {
					return self::respond(['message' => 'Esta vaga não pode ser pausada.'], 400);
				}
				$dados['status'] = 'pausada';
				break;
			case 'retomar':
				if ($status !== 'pausada') {
					return self::respond(['message' => 'Somente vagas pausadas podem ser retomadas.'], 400);
				}
				$dados['status'] = !empty($row['publicada_em']) ? 'publicada' : 'pendente';
				break;
			case 'encerrar':
				if ($status === 'encerrada') {
					return self::respond(['message' => 'Vaga já encerrada.'], 400);
				}
				$dados['status'] = 'encerrada';
				$dados['encerrada_em'] = date('Y-m-d H:i:s');
				break;
			case 'moderacao':
				if (in_array($status, ['encerrada'], true)) {
					return self::respond(['message' => 'Vaga encerrada.'], 400);
				}
				$dados['status'] = 'pendente';
				break;
			default:
				return self::respond(['message' => 'Ação inválida. Use pausar, retomar, encerrar ou moderacao.'], 400);
		}

		(new Database('cj_vagas'))->update('id = '.(int)$vagaId, $dados);
		$atual = CjVaga::getById($vagaId);
		$messages = [
			'pausar'    => 'Vaga pausada.',
			'retomar'   => 'Vaga retomada.',
			'encerrar'  => 'Vaga encerrada.',
			'moderacao' => 'Vaga enviada para moderação.',
		];
		return self::respond([
			'message' => $messages[$acao] ?? 'Atualizado.',
			'vaga'    => $atual ? ConectApiMapper::vaga($atual) : null,
		]);
	}

	public static function enviarModeracao($request, int $vagaId): array {
		$empresa = $request->empresa ?? null;
		if (!$empresa instanceof CjEmpresa || $vagaId <= 0) {
			return self::respond(['message' => 'Inválido.'], 400);
		}
		$row = CjVaga::getById($vagaId);
		if (!$row || (int)$row['id_empresa'] !== (int)$empresa->id) {
			return self::respond(['message' => 'Vaga não encontrada.'], 404);
		}
		(new Database('cj_vagas'))->update('id = '.(int)$vagaId, ['status' => 'pendente']);
		return self::respond(['message' => 'Vaga enviada para moderação.']);
	}
}
