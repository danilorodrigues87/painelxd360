<?php

namespace App\Controller\Api\Student;

use App\Common\Helpers\CrmPessoaHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Environment;
use PDO;
use Throwable;

/**
 * Indicação de amigos → CRM da escola.
 * Conquista sec_indicar é liberada MANUALMENTE pela escola após matrícula.
 */
class Referral {

	private static function ok($data, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	private static function err(string $msg, int $code = 400): array {
		return self::ok(['message' => $msg, 'ok' => false], $code);
	}

	public static function status($request): array {
		$u = $request->user;
		return self::ok([
			'enabled' => self::crmDisponivel((int)$u->id_admin),
		]);
	}

	public static function submit($request): array {
		$u = $request->user;
		$idAdmin = (int)$u->id_admin;
		$idAluno = (int)$u->id;

		if (!self::crmDisponivel($idAdmin)) {
			return self::err('Indicação não disponível nesta escola.', 403);
		}

		$post = $request->getPostVars();
		if (!is_array($post)) {
			$post = [];
		}
		$nome = trim((string)($post['nome'] ?? $post['name'] ?? ''));
		$whatsapp = preg_replace('/\D+/', '', (string)($post['whatsapp'] ?? $post['phone'] ?? ''));
		$email = trim((string)($post['email'] ?? ''));
		$curso = trim((string)($post['curso'] ?? $post['curso_interesse'] ?? ''));

		if ($nome === '' || mb_strlen($nome) < 3) {
			return self::err('Informe o nome completo do indicado.');
		}

		if (strlen($whatsapp) >= 12 && strpos($whatsapp, '55') === 0) {
			$whatsapp = substr($whatsapp, 2);
		}
		if (strlen($whatsapp) < 10 || strlen($whatsapp) > 11) {
			return self::err('Informe um WhatsApp válido com DDD (ex.: 11999998888).');
		}
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return self::err('E-mail inválido.');
		}

		if (!self::dentroDoLimite($idAdmin, $idAluno)) {
			return self::err('Limite de indicações do dia atingido. Tente amanhã.');
		}

		$funilId = self::resolverFunil($idAdmin);
		if ($funilId <= 0) {
			return self::err('Não foi possível preparar o funil do CRM. Execute database/crm_funis.sql ou abra Leads no painel.');
		}

		$indicador = trim((string)($u->nome ?? 'Aluno'));
		$obs = 'Indicação via portal EAD. Indicado por: '.$indicador.' (ID '.$idAluno.').';

		try {
			$res = CrmPessoaHelper::criarOuAtualizarLead($idAdmin, [
				'nome' => $nome,
				'whatsapp' => $whatsapp,
				'email' => $email !== '' ? $email : null,
				'curso_interesse' => $curso !== '' ? $curso : null,
				'origem' => 'Indicação portal',
				'funil_id' => $funilId,
				'visibilidade' => 'publico',
				'historico_obs' => $obs,
			], $idAluno);
			$lead = $res['lead'] ?? null;
			$leadId = ($lead && !empty($lead->id)) ? (int)$lead->id : 0;
		} catch (Throwable $e) {
			return self::err('Falha ao gravar indicação: '.$e->getMessage(), 500);
		}

		if ($leadId <= 0) {
			return self::err('Falha ao gravar indicação (ID não gerado).', 500);
		}

		try {
			CrmPessoaHelper::registrarHistorico($leadId, $idAluno, 'Indicação portal', $obs);
		} catch (Throwable $e) {
			/* histórico opcional */
		}

		return self::ok([
			'ok' => true,
			'message' => 'Indicação enviada! A escola entrará em contato com o indicado.',
			'leadId' => $leadId,
		]);
	}

	private static function pdo(): PDO {
		$host = (string)Environment::get('DB_HOST', 'localhost');
		$name = (string)Environment::get('DB_NAME', '');
		$user = (string)Environment::get('DB_USER', '');
		$pass = (string)Environment::get('DB_PASS', '');
		$pdo = new PDO(
			'mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',
			$user,
			$pass,
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		return $pdo;
	}

	/** Funil ativo da escola; cria "Indicações portal" se não houver nenhum. */
	private static function resolverFunil(int $idAdmin): int {
		try {
			$pdo = self::pdo();
			$stmt = $pdo->prepare('SELECT id FROM crm_funis WHERE id_admin = ? AND ativo = 1 ORDER BY id ASC LIMIT 1');
			$stmt->execute([$idAdmin]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($row && !empty($row['id'])) {
				return (int)$row['id'];
			}
			$ins = $pdo->prepare('INSERT INTO crm_funis (id_admin, nome, ativo, data_cadastro) VALUES (?, ?, 1, NOW())');
			$ins->execute([$idAdmin, 'Indicações portal']);
			return (int)$pdo->lastInsertId();
		} catch (Throwable $e) {
			return 0;
		}
	}

	private static function crmDisponivel(int $idAdmin): bool {
		if ($idAdmin <= 0) {
			return false;
		}
		try {
			$pdo = self::pdo();
			$stmt = $pdo->query("SHOW TABLES LIKE 'crm_leads'");
			if (!$stmt || $stmt->rowCount() === 0) {
				return false;
			}
		} catch (Throwable $e) {
			return false;
		}
		$slugs = ModuleGateHelper::getSlugsEscola($idAdmin);
		if (in_array('ead', $slugs, true) || in_array('leads', $slugs, true)) {
			return true;
		}
		$labels = ModuleGateHelper::getModulosEscola($idAdmin);
		return in_array('Leads', $labels, true) || in_array('Cursos Online', $labels, true);
	}

	/** Máx. 5 indicações / aluno / dia. */
	private static function dentroDoLimite(int $idAdmin, int $idAluno): bool {
		try {
			$pdo = self::pdo();
			$stmt = $pdo->prepare(
				'SELECT COUNT(*) AS c FROM crm_historico h
				INNER JOIN crm_leads l ON l.id = h.lead_id
				WHERE l.id_admin = ?
				AND h.acao = ?
				AND h.observacao LIKE ?
				AND DATE(h.data_registro) = CURDATE()'
			);
			$stmt->execute([
				$idAdmin,
				'Indicação portal',
				'%(ID '.$idAluno.')%',
			]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return ((int)($row['c'] ?? 0)) < 5;
		} catch (Throwable $e) {
			return true;
		}
	}
}
