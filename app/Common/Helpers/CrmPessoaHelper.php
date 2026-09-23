<?php

namespace App\Common\Helpers;

use App\Model\Entity\CjCandidato;
use App\Model\Entity\CrmHistorico;
use App\Model\Entity\CrmLeads;
use App\Model\Entity\User;
use App\Model\Db\Database;

/**
 * Unifica lead CRM ↔ usuário (Cliente/Candidato) e deduplicação.
 */
class CrmPessoaHelper {

	public static function normalizarWhatsapp(?string $whatsapp): string {
		$d = preg_replace('/\D+/', '', (string)$whatsapp);
		if (strlen($d) >= 12 && strpos($d, '55') === 0) {
			$d = substr($d, 2);
		}
		return $d;
	}

	public static function normalizarEmail(?string $email): string {
		return EmailValidator::normalizar((string)$email);
	}

	/** @return CrmLeads|null */
	public static function buscarLeadDuplicado(int $idAdmin, ?string $whatsapp, ?string $email, ?int $excluirLeadId = null): ?CrmLeads {
		$tel = self::normalizarWhatsapp($whatsapp);
		$mail = self::normalizarEmail($email);
		$parts = [];
		if (strlen($tel) >= 10) {
			$parts[] = 'whatsapp = "'.addslashes($tel).'"';
		}
		if ($mail !== '') {
			$parts[] = 'LOWER(email) = "'.addslashes(strtolower($mail)).'"';
		}
		if ($parts === []) {
			return null;
		}
		$where = 'id_admin = '.(int)$idAdmin.' AND ('.implode(' OR ', $parts).')';
		if ($excluirLeadId !== null && $excluirLeadId > 0) {
			$where .= ' AND id != '.(int)$excluirLeadId;
		}
		$row = CrmLeads::getLeads($where, 'id DESC', '1')->fetchObject(CrmLeads::class);
		return $row instanceof CrmLeads ? $row : null;
	}

	/**
	 * Cria ou atualiza lead (dedupe por WhatsApp/e-mail na escola).
	 * @return array{lead:CrmLeads,criado:bool,atualizado:bool}
	 */
	public static function criarOuAtualizarLead(int $idAdmin, array $dados, int $usuarioOperador = 0): array {
		$nome = trim((string)($dados['nome'] ?? ''));
		$whatsapp = self::normalizarWhatsapp($dados['whatsapp'] ?? '');
		$email = self::normalizarEmail($dados['email'] ?? '');
		$exist = self::buscarLeadDuplicado($idAdmin, $whatsapp, $email);

		if ($exist instanceof CrmLeads) {
			if ($nome !== '') {
				$exist->nome = mb_substr($nome, 0, 191);
			}
			if ($whatsapp !== '') {
				$exist->whatsapp = $whatsapp;
			}
			if ($email !== '') {
				$exist->email = $email;
			}
			$curso = trim((string)($dados['curso_interesse'] ?? ''));
			if ($curso !== '') {
				$exist->curso_interesse = mb_substr($curso, 0, 191);
			}
			$origem = trim((string)($dados['origem'] ?? ''));
			if ($origem !== '') {
				$exist->origem = mb_substr($origem, 0, 100);
			}
			if (!empty($dados['bairro'])) {
				$exist->bairro = mb_substr(trim((string)$dados['bairro']), 0, 120);
			}
			if (isset($dados['cidade']) && $dados['cidade'] !== '') {
				$exist->cidade = is_numeric($dados['cidade'])
					? (string)(int)$dados['cidade']
					: mb_substr(trim((string)$dados['cidade']), 0, 120);
			}
			if (isset($dados['valor_estimado']) && (float)$dados['valor_estimado'] > 0) {
				$exist->valor_estimado = (float)$dados['valor_estimado'];
			}
			if (!empty($dados['funil_id']) && (int)$dados['funil_id'] > 0) {
				$exist->funil_id = (int)$dados['funil_id'];
			}
			if (isset($dados['idade']) && (int)$dados['idade'] > 0) {
				$exist->idade = (int)$dados['idade'];
			}
			if (!empty($dados['responsavel_nome'])) {
				$exist->responsavel_nome = mb_substr(trim((string)$dados['responsavel_nome']), 0, 191);
			}
			$exist->atualizarDados();
			if (!empty($dados['historico_obs'])) {
				self::registrarHistorico((int)$exist->id, $usuarioOperador, 'lead_atualizado', (string)$dados['historico_obs']);
			}
			return ['lead' => $exist, 'criado' => false, 'atualizado' => true];
		}

		$lead = new CrmLeads();
		$lead->id_admin = $idAdmin;
		$lead->usuario_id = $usuarioOperador;
		$lead->visibilidade = in_array($dados['visibilidade'] ?? '', ['publico', 'privado'], true)
			? $dados['visibilidade'] : 'publico';
		$lead->funil_id = !empty($dados['funil_id']) ? (int)$dados['funil_id'] : null;
		$lead->nome = mb_substr($nome !== '' ? $nome : 'Lead', 0, 191);
		$lead->whatsapp = $whatsapp;
		$lead->email = $email !== '' ? $email : null;
		$lead->curso_interesse = !empty($dados['curso_interesse'])
			? mb_substr(trim((string)$dados['curso_interesse']), 0, 191) : null;
		$lead->origem = !empty($dados['origem']) ? mb_substr(trim((string)$dados['origem']), 0, 100) : null;
		$lead->bairro = !empty($dados['bairro']) ? mb_substr(trim((string)$dados['bairro']), 0, 120) : null;
		if (isset($dados['cidade']) && $dados['cidade'] !== '') {
			$lead->cidade = is_numeric($dados['cidade'])
				? (string)(int)$dados['cidade']
				: mb_substr(trim((string)$dados['cidade']), 0, 120);
		}
		if (isset($dados['valor_estimado']) && (float)$dados['valor_estimado'] > 0) {
			$lead->valor_estimado = (float)$dados['valor_estimado'];
		}
		if (isset($dados['idade']) && (int)$dados['idade'] > 0) {
			$lead->idade = (int)$dados['idade'];
		}
		if (!empty($dados['responsavel_nome'])) {
			$lead->responsavel_nome = mb_substr(trim((string)$dados['responsavel_nome']), 0, 191);
		}
		$lead->status = 'novo';
		$lead->status_wa = 'pendente';
		$lead->cadastrar();

		$obs = (string)($dados['historico_obs'] ?? 'Lead cadastrado.');
		self::registrarHistorico((int)$lead->id, $usuarioOperador, 'lead_cadastrado', $obs);

		return ['lead' => $lead, 'criado' => true, 'atualizado' => false];
	}

	public static function vincularLeadUsuario(int $leadId, int $idUsuario, int $idAdmin): bool {
		if ($leadId <= 0 || $idUsuario <= 0 || !CrmLeads::colunaIdUsuarioExiste()) {
			return false;
		}
		return (bool)(new Database('crm_leads'))->update(
			'id = '.(int)$leadId.' AND id_admin = '.(int)$idAdmin,
			['id_usuario' => (int)$idUsuario]
		);
	}

	/**
	 * Converte lead em aluno comercial (usuarios.nivel = Cliente).
	 * @return array{ok:bool,message:string,id_usuario?:int,criado?:bool,promovido?:bool}
	 */
	public static function converterLeadEmCliente(int $idAdmin, int $leadId, int $usuarioOperador): array {
		$lead = CrmLeads::getLeads(
			'id = '.(int)$leadId.' AND id_admin = '.(int)$idAdmin
		)->fetchObject(CrmLeads::class);
		if (!$lead instanceof CrmLeads) {
			return ['ok' => false, 'message' => 'Lead não encontrado.'];
		}

		if (CrmLeads::colunaIdUsuarioExiste() && !empty($lead->id_usuario)) {
			$u = User::getUserById((int)$lead->id_usuario);
			if ($u instanceof User && (string)($u->nivel ?? '') === 'Cliente' && (int)($u->id_admin ?? 0) === $idAdmin) {
				return [
					'ok' => true,
					'message' => 'Lead já vinculado a um aluno.',
					'id_usuario' => (int)$u->id,
					'criado' => false,
					'promovido' => false,
				];
			}
		}

		$email = self::normalizarEmail($lead->email ?? '');
		$whatsapp = self::normalizarWhatsapp($lead->whatsapp ?? '');

		$user = null;
		if ($email !== '') {
			$user = User::getUserByEmail($email);
		}

		if ($user instanceof User) {
			if ((int)($user->id_admin ?? 0) !== $idAdmin && (string)($user->nivel ?? '') !== 'Candidato') {
				return ['ok' => false, 'message' => 'E-mail já usado por outra conta no sistema.'];
			}
			if ((string)($user->nivel ?? '') === 'Candidato') {
				$user->nome = $lead->nome ?: $user->nome;
				$user->nivel = 'Cliente';
				$user->id_admin = $idAdmin;
				if ($whatsapp !== '') {
					$user->whatsapp = $whatsapp;
				}
				if (!empty($lead->bairro)) {
					$user->bairro = $lead->bairro;
				}
				$user->atualizar();
				self::vincularLeadUsuario($leadId, (int)$user->id, $idAdmin);
				self::registrarHistorico(
					$leadId,
					$usuarioOperador,
					'convertido_aluno',
					'Candidato promovido a aluno (Cliente) a partir do lead #'.$leadId.'.'
				);
				return [
					'ok' => true,
					'message' => 'Candidato convertido em aluno.',
					'id_usuario' => (int)$user->id,
					'criado' => false,
					'promovido' => true,
				];
			}
			if ((string)($user->nivel ?? '') === 'Cliente' && (int)($user->id_admin ?? 0) === $idAdmin) {
				self::vincularLeadUsuario($leadId, (int)$user->id, $idAdmin);
				self::registrarHistorico(
					$leadId,
					$usuarioOperador,
					'vinculado_aluno',
					'Lead vinculado ao aluno existente #'.(int)$user->id.'.'
				);
				return [
					'ok' => true,
					'message' => 'Lead vinculado ao aluno existente.',
					'id_usuario' => (int)$user->id,
					'criado' => false,
					'promovido' => false,
				];
			}
			return ['ok' => false, 'message' => 'E-mail já cadastrado com outro perfil.'];
		}

		$novo = new User();
		$novo->nome = (string)$lead->nome;
		$novo->email = $email !== '' ? $email : ('lead'.$leadId.'@sem-email.local');
		$novo->senha = password_hash('12345678', PASSWORD_DEFAULT);
		$novo->nivel = 'Cliente';
		$novo->id_admin = $idAdmin;
		$novo->whatsapp = $whatsapp;
		$novo->bairro = $lead->bairro ?? '';
		$novo->ativo = 's';
		$novo->cadastrar();
		if ((int)$novo->id <= 0) {
			return ['ok' => false, 'message' => 'Não foi possível criar o aluno.'];
		}

		self::vincularLeadUsuario($leadId, (int)$novo->id, $idAdmin);
		self::registrarHistorico(
			$leadId,
			$usuarioOperador,
			'convertido_aluno',
			'Aluno criado a partir do lead #'.$leadId.'. Senha inicial: 12345678 (orientar troca no 1º acesso).'
		);

		return [
			'ok' => true,
			'message' => 'Aluno criado a partir do lead.',
			'id_usuario' => (int)$novo->id,
			'criado' => true,
			'promovido' => false,
		];
	}

	/**
	 * Após matrícula: marca lead como matriculado (por id_lead, id_usuario ou match e-mail/WhatsApp).
	 * @return int|null ID do lead atualizado
	 */
	public static function sincronizarLeadMatriculado(int $idAdmin, int $idAluno, int $usuarioOperador, ?int $leadId = null): ?int {
		$aluno = User::getUserById($idAluno);
		if (!$aluno instanceof User || (int)($aluno->id_admin ?? 0) !== $idAdmin) {
			return null;
		}

		$lead = null;
		if ($leadId !== null && $leadId > 0) {
			$lead = CrmLeads::getLeads(
				'id = '.(int)$leadId.' AND id_admin = '.(int)$idAdmin
			)->fetchObject(CrmLeads::class);
		}
		if (!$lead instanceof CrmLeads && CrmLeads::colunaIdUsuarioExiste()) {
			$lead = CrmLeads::getLeads(
				'id_admin = '.(int)$idAdmin.' AND id_usuario = '.(int)$idAluno,
				'id DESC',
				'1'
			)->fetchObject(CrmLeads::class);
		}
		if (!$lead instanceof CrmLeads) {
			$lead = self::buscarLeadDuplicado(
				$idAdmin,
				(string)($aluno->whatsapp ?? ''),
				(string)($aluno->email ?? '')
			);
		}
		if (!$lead instanceof CrmLeads) {
			return null;
		}

		self::vincularLeadUsuario((int)$lead->id, $idAluno, $idAdmin);

		if ((string)$lead->status === 'matriculado') {
			return (int)$lead->id;
		}

		$statusAnterior = (string)$lead->status;
		$lead->status = 'matriculado';
		$lead->atualizarStatus();
		self::registrarHistorico(
			(int)$lead->id,
			$usuarioOperador,
			'status_alterado',
			'Matrícula registrada — status alterado para Matriculado (automático).'
		);

		if ($statusAnterior !== 'matriculado' && class_exists(\App\Controller\Admin\CrmLeads::class)) {
			\App\Controller\Admin\CrmLeads::dispararAutomacaoStatusPublico($lead, $statusAnterior, 'matriculado', $usuarioOperador);
		}

		return (int)$lead->id;
	}

	public static function registrarHistorico(int $leadId, int $usuarioId, string $acao, string $observacao): void {
		try {
			$hist = new CrmHistorico();
			$hist->lead_id = $leadId;
			$hist->usuario_id = $usuarioId;
			$hist->acao = mb_substr($acao, 0, 50);
			$hist->observacao = $observacao;
			$hist->cadastrar();
		} catch (\Throwable $e) {
			// ignore
		}
	}
}
