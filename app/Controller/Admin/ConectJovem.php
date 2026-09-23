<?php

namespace App\Controller\Admin;

use App\Common\Helpers\ConectIdadeHelper;
use App\Common\Helpers\TenantHelper;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\User;
use App\Session\User\Login as SessionUser;
use App\Utils\View;

class ConectJovem extends Page {

	public static function index($request): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$candidatos = CjCandidato::tabelaExiste()
			? CjCandidato::listarPorEscola($idAdmin, 100)
			: [];

		$alertSql = !CjCandidato::tabelaExiste()
			? '<div class="alert alert-warning">Execute o SQL <code>database/conect_jovem.sql</code> no phpMyAdmin.</div>'
			: '';

		$cards = CjCandidato::tabelaExiste()
			? '<div class="row g-3 mb-4">'
				.'<div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body">'
				.'<div class="text-muted small">Candidatos vinculados</div>'
				.'<div class="fs-3 fw-bold">'.count($candidatos).'</div>'
				.'</div></div></div>'
				.'<div class="col-md-8"><div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">'
				.'<div><strong>Leads de captação</strong><div class="small text-muted">Origem Conecta Jovem no CRM.</div></div>'
				.'<a href="'.URL.'/painel/crm" class="btn btn-outline-primary btn-sm">Abrir CRM</a>'
				.'</div></div></div></div>'
			: '';

		$rows = '';
		foreach ($candidatos as $c) {
			$tipo = htmlspecialchars((string)($c['tipo'] ?? ''));
			$nome = htmlspecialchars((string)($c['nome'] ?? ''));
			$email = htmlspecialchars((string)($c['email'] ?? ''));
			$whats = htmlspecialchars((string)($c['whatsapp'] ?? ''));
			$whatsLink = preg_replace('/\D+/', '', (string)($c['whatsapp'] ?? ''));
			$whatsCell = $whatsLink !== ''
				? '<a href="https://wa.me/55'.$whatsLink.'" target="_blank" rel="noopener">'.$whats.'</a>'
				: '<span class="text-muted">—</span>';
			$rows .= '<tr>'
				.'<td>'.$nome.'</td>'
				.'<td class="small">'.$email.'</td>'
				.'<td class="small">'.$whatsCell.'</td>'
				.'<td><span class="badge bg-secondary">'.$tipo.'</span></td>'
				.'</tr>';
		}
		if ($rows === '') {
			$rows = '<tr><td colspan="4" class="text-muted">Nenhum candidato cadastrado pela escola ainda.</td></tr>';
		}

		$content = View::render('admin/modules/conect/index', [
			'alert_sql'    => $alertSql,
			'cards'        => $cards,
			'cadastro_url' => URL.'/painel/conect/candidatos/novo',
			'rows_candidatos' => $rows,
		]);

		return parent::getPage('Conecta Jovem', $content, parent::getMenu('conect_jovem', parent::getPermittedModules()));
	}

	public static function novoCandidato($request): string {
		$content = View::render('admin/modules/conect/candidato_form', [
			'save_url' => URL.'/painel/conect/candidatos/save',
		]);
		return parent::getPage('Cadastrar candidato', $content, parent::getMenu('conect_jovem', parent::getPermittedModules()));
	}

	public static function salvarCandidato($request): void {
		$idAdmin = TenantHelper::getIdAdmin();
		$userId = (int)(SessionUser::getUserLogedData()['usuario']['id'] ?? 0);
		$post = $request->getPostVars();
		$nome = trim((string)($post['nome'] ?? ''));
		$email = strtolower(trim((string)($post['email'] ?? '')));
		$whatsapp = preg_replace('/\D+/', '', (string)($post['whatsapp'] ?? ''));
		$resumo = trim((string)($post['resumo'] ?? ''));

		if ($nome === '' || !CjCandidato::tabelaExiste()) {
			header('Location: '.URL.'/painel/conect/candidatos/novo?erro=1');
			exit;
		}

		$nascVal = ConectIdadeHelper::extrairValidarNascimento($post, true);
		if (!$nascVal['ok']) {
			header('Location: '.URL.'/painel/conect/candidatos/novo?erro=nascimento');
			exit;
		}

		$insert = [
			'id_admin'                 => $idAdmin,
			'tipo'                     => 'escola_cadastro',
			'cadastrado_por_usuario_id'=> $userId > 0 ? $userId : null,
			'nome'                     => $nome,
			'email'                    => $email,
			'whatsapp'                 => $whatsapp,
			'resumo'                   => $resumo,
			'status'                   => 'ativo',
		];
		if (!empty($nascVal['dados'])) {
			$insert = array_merge($insert, $nascVal['dados']);
		}

		$candidatoId = CjCandidato::inserir($insert);

		if ($email !== '' && $candidatoId && !User::getUserByEmail($email)) {
			$senhaTemp = bin2hex(random_bytes(4));
			$user = new User();
			$user->nome = $nome;
			$user->email = $email;
			$user->senha = password_hash($senhaTemp, PASSWORD_DEFAULT);
			$user->nivel = 'Candidato';
			$user->id_admin = $idAdmin;
			$user->whatsapp = $whatsapp;
			$user->ativo = 1;
			$user->cadastrar();
			if ((int)$user->id > 0) {
				CjCandidato::atualizar($candidatoId, ['id_usuario' => (int)$user->id]);
			}
		}

		header('Location: '.URL.'/painel/conect?ok=1');
		exit;
	}
}
