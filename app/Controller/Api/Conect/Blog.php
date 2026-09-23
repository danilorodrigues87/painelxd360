<?php

namespace App\Controller\Api\Conect;

use App\Common\Helpers\ConectApiMapper;
use App\Common\Helpers\ConectBlogSanitizer;
use App\Model\Entity\CjBlogCategoria;
use App\Model\Entity\CjBlogComentario;
use App\Model\Entity\CjBlogPost;
use App\Model\Entity\User;

class Blog {

	private const COM_RATE_WINDOW = 3600;
	private const COM_RATE_MAX = 10;

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function categorias($request): array {
		if (!CjBlogCategoria::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		$rows = CjBlogCategoria::listarAtivas();
		$items = array_map([ConectApiMapper::class, 'blogCategoria'], $rows);
		return self::respond(['items' => $items, 'sqlOk' => true]);
	}

	public static function posts($request): array {
		if (!CjBlogPost::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		$q = $request->getQueryParams();
		$filtros = [
			'limit'  => (int)($q['limit'] ?? 12),
			'offset' => (int)($q['offset'] ?? 0),
		];
		if (!empty($q['categoria'])) {
			$filtros['categoria_slug'] = (string)$q['categoria'];
		}
		if (!empty($q['q'])) {
			$filtros['q'] = (string)$q['q'];
		}
		$rows = CjBlogPost::queryLista($filtros);
		foreach ($rows as &$row) {
			$row['comentarios_count'] = CjBlogComentario::contarPorPost((int)$row['id']);
		}
		unset($row);
		$items = array_map([ConectApiMapper::class, 'blogPostResumo'], $rows);
		return self::respond(['items' => $items, 'sqlOk' => true]);
	}

	public static function postDetalhe($request, string $slug): array {
		if (!CjBlogPost::tabelaExiste()) {
			return self::respond(['message' => 'Módulo não instalado.'], 503);
		}
		$row = CjBlogPost::getBySlug($slug, true);
		if (!$row) {
			return self::respond(['message' => 'Artigo não encontrado.'], 404);
		}
		$row['comentarios_count'] = CjBlogComentario::contarPorPost((int)$row['id']);
		return self::respond(['post' => ConectApiMapper::blogPost($row), 'sqlOk' => true]);
	}

	public static function comentarios($request, string $slug): array {
		if (!CjBlogPost::tabelaExiste() || !CjBlogComentario::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		$post = CjBlogPost::getBySlug($slug, true);
		if (!$post) {
			return self::respond(['message' => 'Artigo não encontrado.'], 404);
		}
		$q = $request->getQueryParams();
		$limit = (int)($q['limit'] ?? 100);
		$offset = (int)($q['offset'] ?? 0);
		$rows = CjBlogComentario::listarPorPost((int)$post['id'], $limit, $offset);
		$items = array_map([ConectApiMapper::class, 'blogComentario'], $rows);
		return self::respond([
			'items'  => $items,
			'total'  => CjBlogComentario::contarPorPost((int)$post['id']),
			'sqlOk'  => true,
		]);
	}

	public static function criarComentario($request, string $slug): array {
		$user = $request->user ?? null;
		if (!$user instanceof User) {
			return self::respond(['message' => 'Faça login para comentar.'], 401);
		}
		if (!CjBlogPost::tabelaExiste() || !CjBlogComentario::tabelaExiste()) {
			return self::respond(['message' => 'Módulo não instalado.'], 503);
		}
		$post = CjBlogPost::getBySlug($slug, true);
		if (!$post) {
			return self::respond(['message' => 'Artigo não encontrado.'], 404);
		}

		$postVars = $request->getPostVars() ?: [];
		$texto = ConectBlogSanitizer::textoComentario((string)($postVars['texto'] ?? ''));
		if ($texto === '') {
			return self::respond(['message' => 'Escreva um comentário.'], 400);
		}

		$role = (string)($request->conectRole ?? '');
		$nome = (string)($user->nome ?? 'Usuário');
		if ($role === 'empresa') {
			$empresa = $request->empresa ?? null;
			if ($empresa) {
				$fantasia = trim((string)($empresa->nome_fantasia ?? ''));
				if ($fantasia !== '') {
					$nome = $fantasia;
				}
			}
		} elseif ($role === 'candidato') {
			$candidato = $request->candidato ?? null;
			if ($candidato && trim((string)($candidato->nome ?? '')) !== '') {
				$nome = trim((string)$candidato->nome);
			}
		} else {
			return self::respond(['message' => 'Perfil não autorizado.'], 403);
		}

		if (!self::permitirComentario((int)$user->id)) {
			return self::respond(['message' => 'Muitos comentários em pouco tempo. Aguarde.'], 429);
		}

		$id = CjBlogComentario::inserir([
			'id_post'        => (int)$post['id'],
			'id_usuario'     => (int)$user->id,
			'tipo_autor'     => $role,
			'nome_exibicao'  => mb_substr($nome, 0, 120),
			'texto'          => $texto,
			'status'         => 'publicado',
		]);

		$row = CjBlogComentario::getById($id);
		return self::respond([
			'message'   => 'Comentário publicado.',
			'comentario'=> $row ? ConectApiMapper::blogComentario($row) : null,
		], 201);
	}

	public static function excluirComentario($request, int $id): array {
		$user = $request->user ?? null;
		if (!$user instanceof User) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (!CjBlogComentario::tabelaExiste()) {
			return self::respond(['message' => 'Módulo não instalado.'], 503);
		}
		$row = CjBlogComentario::getById($id);
		if (!$row || ($row['status'] ?? '') === 'removido') {
			return self::respond(['message' => 'Comentário não encontrado.'], 404);
		}
		if ((int)($row['id_usuario'] ?? 0) !== (int)$user->id) {
			return self::respond(['message' => 'Você só pode excluir seus comentários.'], 403);
		}
		CjBlogComentario::atualizar($id, ['status' => 'removido']);
		return self::respond(['message' => 'Comentário removido.']);
	}

	private static function permitirComentario(int $userId): bool {
		$dir = sys_get_temp_dir().'/conect_blog_com_rate';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$file = $dir.'/'.md5((string)$userId).'.json';
		$now = time();
		$data = ['times' => []];
		if (is_file($file)) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (is_array($decoded) && isset($decoded['times']) && is_array($decoded['times'])) {
				$data = $decoded;
			}
		}
		$data['times'] = array_values(array_filter(
			$data['times'],
			static fn($t) => is_int($t) && $t > ($now - self::COM_RATE_WINDOW)
		));
		if (count($data['times']) >= self::COM_RATE_MAX) {
			return false;
		}
		$data['times'][] = $now;
		@file_put_contents($file, json_encode($data));
		return true;
	}
}
