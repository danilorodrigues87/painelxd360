<?php

namespace App\Controller\Api\Student;

use App\Common\Helpers\LmsAiService;
use App\Model\Entity\LmsAiConversa;
use App\Model\Entity\LmsAula;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsMaterial;

class AiTutor {

	private static function ok($data, int $code = 200): array {
		return ['code' => $code, 'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
	}

	private static function err(string $msg, int $code = 400): array {
		return self::ok(['message' => $msg], $code);
	}

	private static function map(LmsAiConversa $c): array {
		$msgs = json_decode((string)($c->messages ?? '[]'), true);
		if (!is_array($msgs)) {
			$msgs = [];
		}
		return [
			'id' => (string)$c->id,
			'title' => (string)$c->titulo,
			'createdAt' => $c->created_at ? date('c', strtotime($c->created_at)) : date('c'),
			'updatedAt' => $c->updated_at ? date('c', strtotime($c->updated_at)) : date('c'),
			'messages' => $msgs,
		];
	}

	private static function buildLessonContext(?int $idAula, int $idAdmin, ?int $idCurso = null): string {
		$parts = [];
		if ($idCurso) {
			$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
			if ($curso) {
				$parts[] = 'Curso: '.(string)($curso->short_description ?: $curso->slug);
			}
		}
		if ($idAula) {
			$aula = LmsAula::getByIdAdmin($idAula, $idAdmin);
			if ($aula) {
				$parts[] = 'Aula: '.(string)$aula->titulo;
				$desc = strip_tags((string)($aula->descricao ?? ''));
				if ($desc !== '') {
					$parts[] = 'Descrição da aula: '.$desc;
				}
				foreach (LmsMaterial::listByAula($idAula, $idAdmin) as $m) {
					$parts[] = 'Material: '.(string)($m->label ?? '').' ('.(string)($m->tipo ?? '').')';
				}
			}
		}
		return mb_substr(implode("\n", $parts), 0, 3500);
	}

	private static function systemPrompt(string $context): string {
		$base = "Você é o assistente pedagógico do CTI Educacional. "
			."Responda em português, de forma clara e didática. "
			."REGRAS OBRIGATÓRIAS:\n"
			."1) Fale APENAS sobre o conteúdo da aula/curso abaixo. Se o aluno sair do assunto, redirecione educadamente.\n"
			."2) NÃO entregue gabaritos completos de avaliações; ajude a raciocinar.\n"
			."3) Recuse pedidos ilegais, ofensivos, sexualizados ou que peçam dados de outros alunos.\n"
			."4) Se não souber com base no contexto, diga que não tem essa informação no material da aula.\n";
		if ($context !== '') {
			$base .= "\nCONTEXTO DA AULA:\n".$context;
		}
		return $base;
	}

	public static function list($request) {
		$u = $request->user;
		$out = [];
		foreach (LmsAiConversa::listByAluno((int)$u->id, (int)$u->id_admin) as $c) {
			$out[] = self::map($c);
		}
		return self::ok($out);
	}

	public static function create($request) {
		$u = $request->user;
		$post = $request->getPostVars() ?: [];
		$c = new LmsAiConversa();
		$c->id_aluno = (int)$u->id;
		$c->id_admin = (int)$u->id_admin;
		$c->titulo = trim((string)($post['title'] ?? 'Nova conversa')) ?: 'Nova conversa';
		$meta = [
			'courseId' => isset($post['courseId']) ? (string)$post['courseId'] : null,
			'lessonId' => isset($post['lessonId']) ? (string)$post['lessonId'] : null,
		];
		$c->messages = [
			[
				'id' => 'meta',
				'role' => 'system',
				'content' => json_encode($meta, JSON_UNESCAPED_UNICODE),
				'createdAt' => date('c'),
			],
		];
		$id = $c->salvar();
		$c = LmsAiConversa::getByIdAdmin($id, (int)$u->id_admin);
		return self::ok(self::map($c));
	}

	public static function sendMessage($request, $id) {
		$u = $request->user;
		$c = LmsAiConversa::getByIdAdmin((int)$id, (int)$u->id_admin);
		if (!$c || (int)$c->id_aluno !== (int)$u->id) {
			return self::err('Conversa não encontrada.', 404);
		}
		$post = $request->getPostVars() ?: [];
		$content = trim((string)($post['content'] ?? ''));
		if ($content === '') {
			return self::err('Mensagem vazia.');
		}

		$msgs = json_decode((string)($c->messages ?? '[]'), true) ?: [];
		$userCount = 0;
		foreach ($msgs as $m) {
			if (($m['role'] ?? '') === 'user') {
				$userCount++;
			}
		}
		if ($userCount >= 40) {
			return self::err('Limite de mensagens desta conversa atingido. Abra uma nova conversa.', 403);
		}

		$courseId = isset($post['courseId']) ? (int)$post['courseId'] : null;
		$lessonId = isset($post['lessonId']) ? (int)$post['lessonId'] : null;
		if (!$courseId || !$lessonId) {
			foreach ($msgs as $m) {
				if (($m['id'] ?? '') === 'meta' && ($m['role'] ?? '') === 'system') {
					$meta = json_decode((string)($m['content'] ?? '{}'), true) ?: [];
					$courseId = $courseId ?: (int)($meta['courseId'] ?? 0);
					$lessonId = $lessonId ?: (int)($meta['lessonId'] ?? 0);
				}
			}
		}
		$context = self::buildLessonContext($lessonId ?: null, (int)$u->id_admin, $courseId ?: null);

		$msgs[] = [
			'id' => 'm_'.time().'_u',
			'role' => 'user',
			'content' => $content,
			'createdAt' => date('c'),
		];

		$chatForAi = [];
		foreach ($msgs as $m) {
			$role = $m['role'] ?? '';
			if ($role === 'system' || ($m['id'] ?? '') === 'meta') {
				continue;
			}
			$chatForAi[] = $m;
		}

		$reply = LmsAiService::chat((int)$u->id_admin, $chatForAi, self::systemPrompt($context), 'tutor');
		$assistant = [
			'id' => 'm_'.time().'_a',
			'role' => 'assistant',
			'content' => $reply,
			'createdAt' => date('c'),
		];
		$msgs[] = $assistant;
		$c->messages = $msgs;
		$c->salvar();
		return self::ok($assistant);
	}
}
