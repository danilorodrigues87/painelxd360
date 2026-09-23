<?php

namespace App\Common\Help;

use App\Model\Entity\PlatformHelpCategoria;
use App\Model\Entity\PlatformHelpArtigo;

/**
 * Tutoriais padrão da Central de Ajuda.
 * video_url fica vazio para gravação posterior; reexecutar não apaga vídeo já preenchido.
 */
class PlatformHelpSeed {

	/**
	 * @return array{cats:int,arts:int,updated:int,created:int}
	 */
	public static function aplicar(): array {
		if (!PlatformHelpCategoria::tabelasExistem()) {
			throw new \RuntimeException('Execute database/platform_help.sql antes.');
		}

		$cats = 0;
		$created = 0;
		$updated = 0;
		$catIds = [];

		foreach (self::categorias() as $c) {
			$ob = PlatformHelpCategoria::getBySlug($c['slug']);
			if (!$ob) {
				$ob = new PlatformHelpCategoria();
				$ob->slug = $c['slug'];
			}
			$ob->titulo = $c['titulo'];
			$ob->ordem = (int)$c['ordem'];
			$ob->ativo = 1;
			$ob->salvar();
			$catIds[$c['slug']] = (int)$ob->id;
			$cats++;
		}

		foreach (self::artigos() as $a) {
			$idCat = $catIds[$a['cat']] ?? 0;
			if ($idCat <= 0) {
				continue;
			}
			$ob = PlatformHelpArtigo::getBySlug($a['slug'], false);
			$isNew = !$ob;
			if (!$ob) {
				$ob = new PlatformHelpArtigo();
				$ob->slug = $a['slug'];
				$ob->video_url = null;
				$ob->video_titulo = $a['video_titulo'] ?? ('Tutorial: '.$a['titulo']);
			} elseif (trim((string)($ob->video_url ?? '')) === '') {
				$ob->video_titulo = $a['video_titulo'] ?? ('Tutorial: '.$a['titulo']);
			}
			$ob->id_categoria = $idCat;
			$ob->titulo = $a['titulo'];
			$ob->resumo = $a['resumo'];
			$ob->corpo = $a['corpo'];
			$ob->ordem = (int)$a['ordem'];
			$ob->publicado = 1;
			$ob->salvar();
			if ($isNew) {
				$created++;
			} else {
				$updated++;
			}
		}

		return [
			'cats' => $cats,
			'arts' => $created + $updated,
			'created' => $created,
			'updated' => $updated,
		];
	}

	/**
	 * @return list<array{titulo:string,slug:string,ordem:int}>
	 */
	public static function exportCategorias(): array {
		return self::categorias();
	}

	/**
	 * @return list<array{cat:string,titulo:string,slug:string,resumo:string,ordem:int,corpo:string,video_titulo?:string}>
	 */
	public static function exportArtigos(): array {
		return self::artigos();
	}

	/**
	 * SQL idempotente para phpMyAdmin.
	 * Não sobrescreve video_url se já estiver preenchido.
	 */
	public static function gerarSql(): string {
		$esc = static function ($s): string {
			return str_replace(["\\", "'"], ["\\\\", "''"], (string)$s);
		};
		$out = [];
		$out[] = '-- =============================================================================';
		$out[] = '-- Tutoriais da Central de Ajuda (Painel CTI)';
		$out[] = '-- Cole no phpMyAdmin DEPOIS de database/platform_help.sql (tabelas criadas).';
		$out[] = '-- video_url fica NULL. Se já houver URL de vídeo, o UPDATE preserva.';
		$out[] = '-- Gerado por App\\Common\\Help\\PlatformHelpSeed::gerarSql()';
		$out[] = '-- =============================================================================';
		$out[] = 'SET NAMES utf8mb4;';
		$out[] = '';

		foreach (self::categorias() as $c) {
			$slug = $esc($c['slug']);
			$titulo = $esc($c['titulo']);
			$ordem = (int)$c['ordem'];
			$out[] = "INSERT INTO `platform_help_categorias` (`titulo`, `slug`, `ordem`, `ativo`)";
			$out[] = "SELECT '{$titulo}', '{$slug}', {$ordem}, 1";
			$out[] = "FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `platform_help_categorias` WHERE `slug` = '{$slug}');";
			$out[] = "UPDATE `platform_help_categorias` SET `titulo` = '{$titulo}', `ordem` = {$ordem}, `ativo` = 1 WHERE `slug` = '{$slug}';";
			$out[] = '';
		}

		foreach (self::artigos() as $a) {
			$cat = $esc($a['cat']);
			$slug = $esc($a['slug']);
			$titulo = $esc($a['titulo']);
			$resumo = $esc($a['resumo']);
			$corpo = $esc($a['corpo']);
			$vt = $esc($a['video_titulo'] ?? ('Tutorial: '.$a['titulo']));
			$ordem = (int)$a['ordem'];
			$out[] = "-- Artigo: {$a['slug']}";
			$out[] = "INSERT INTO `platform_help_artigos` (`id_categoria`, `titulo`, `slug`, `resumo`, `corpo`, `video_url`, `video_titulo`, `ordem`, `publicado`)";
			$out[] = "SELECT c.id, '{$titulo}', '{$slug}', '{$resumo}', '{$corpo}', NULL, '{$vt}', {$ordem}, 1";
			$out[] = "FROM `platform_help_categorias` c WHERE c.slug = '{$cat}'";
			$out[] = "AND NOT EXISTS (SELECT 1 FROM `platform_help_artigos` WHERE `slug` = '{$slug}');";
			$out[] = "UPDATE `platform_help_artigos` a";
			$out[] = "INNER JOIN `platform_help_categorias` c ON c.slug = '{$cat}'";
			$out[] = "SET a.id_categoria = c.id,";
			$out[] = "    a.titulo = '{$titulo}',";
			$out[] = "    a.resumo = '{$resumo}',";
			$out[] = "    a.corpo = '{$corpo}',";
			$out[] = "    a.video_titulo = IF(a.video_url IS NULL OR TRIM(a.video_url) = '', '{$vt}', a.video_titulo),";
			$out[] = "    a.ordem = {$ordem},";
			$out[] = "    a.publicado = 1";
			$out[] = "WHERE a.slug = '{$slug}';";
			$out[] = '';
		}

		$out[] = '-- Fim dos tutoriais.';
		return implode("\n", $out)."\n";
	}

	/** @return list<array{titulo:string,slug:string,ordem:int}> */
	private static function categorias(): array {
		return [
			['titulo' => 'Primeiros passos', 'slug' => 'primeiros-passos', 'ordem' => 10],
			['titulo' => 'Usuários e cadastros', 'slug' => 'usuarios-cadastros', 'ordem' => 20],
			['titulo' => 'Pedagógico', 'slug' => 'pedagogico', 'ordem' => 30],
			['titulo' => 'Portal EAD / Cursos Online', 'slug' => 'portal-ead', 'ordem' => 40],
			['titulo' => 'CRM e Leads', 'slug' => 'crm-leads', 'ordem' => 50],
			['titulo' => 'WhatsApp', 'slug' => 'whatsapp', 'ordem' => 60],
			['titulo' => 'Redes sociais', 'slug' => 'redes-sociais', 'ordem' => 70],
			['titulo' => 'Campanhas e e-mail', 'slug' => 'campanhas-email', 'ordem' => 80],
			['titulo' => 'Financeiro', 'slug' => 'financeiro', 'ordem' => 90],
			['titulo' => 'Vendas e estoque', 'slug' => 'vendas-estoque', 'ordem' => 100],
			['titulo' => 'Agenda', 'slug' => 'agenda', 'ordem' => 110],
			['titulo' => 'Configurações', 'slug' => 'configuracoes', 'ordem' => 120],
			['titulo' => 'Assistente IA', 'slug' => 'assistente-ia', 'ordem' => 125],
			['titulo' => 'Suporte', 'slug' => 'suporte', 'ordem' => 130],
			['titulo' => 'Portal do aluno', 'slug' => 'portal-aluno', 'ordem' => 140],
		];
	}

	/** @return list<array{cat:string,titulo:string,slug:string,resumo:string,ordem:int,corpo:string,video_titulo?:string}> */
	private static function artigos(): array {
		$out = [];
		foreach ([
			self::artPrimeiros(),
			self::artUsuarios(),
			self::artPedagogico(),
			self::artEad(),
			self::artCrm(),
			self::artWhatsapp(),
			self::artSocial(),
			self::artCampanhas(),
			self::artFinanceiro(),
			self::artVendas(),
			self::artAgenda(),
			self::artConfig(),
			self::artAssistenteIa(),
			self::artSuporte(),
			self::artPortalAluno(),
		] as $grupo) {
			foreach ($grupo as $a) {
				$out[] = $a;
			}
		}
		return $out;
	}

	private static function wrap(string $intro, array $passos, string $dicas = '', string $menu = ''): string {
		$html = '<p>'.$intro.'</p>';
		if ($menu !== '') {
			$html .= '<p><strong>Onde encontrar:</strong> '.$menu.'</p>';
		}
		$html .= '<h2>Passo a passo</h2><ol>';
		foreach ($passos as $p) {
			$html .= '<li>'.$p.'</li>';
		}
		$html .= '</ol>';
		if ($dicas !== '') {
			$html .= '<h2>Dicas e cuidados</h2>'.$dicas;
		}
		$html .= '<p class="text-muted"><em>O vídeo deste tutorial será publicado em breve. Enquanto isso, use este guia escrito.</em></p>';
		return $html;
	}

	private static function artPrimeiros(): array {
		return [
			[
				'cat' => 'primeiros-passos',
				'titulo' => 'Bem-vindo ao Painel CTI',
				'slug' => 'bem-vindo',
				'resumo' => 'Visão geral do painel, menu e o que cada área faz.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'O Painel CTI é o sistema completo da sua escola: cadastros, matrículas, financeiro, CRM, WhatsApp, campanhas, EAD, redes sociais e suporte — conforme o plano contratado e as permissões de cada usuário.',
					[
						'Acesse <strong>/painel</strong> com o e-mail e a senha fornecidos pela escola ou pelo suporte CTI.',
						'No primeiro acesso, leia e aceite os <strong>Termos de Uso (LGPD)</strong> — sem isso os módulos ficam bloqueados.',
						'Explore o <strong>menu lateral</strong>: só aparecem itens liberados no plano da escola e no checklist do seu usuário.',
						'Use o <strong>Dashboard</strong> (página inicial) para ver resumos de matrículas, financeiro, leads e indicadores.',
						'Em dúvida, abra <strong>Ajuda</strong> (esta central) ou <strong>Suporte</strong> para abrir um chamado com a equipe CTI.',
						'No topo você pode alternar o <strong>tema claro/escuro</strong> e acessar o perfil.',
					],
					'<ul><li>Diretor costuma ter mais módulos liberados automaticamente.</li><li>Funcionários recebem permissões no cadastro de usuários.</li><li>Não compartilhe login entre pessoas.</li></ul>',
					'Dashboard e menu lateral'
				),
			],
			[
				'cat' => 'primeiros-passos',
				'titulo' => 'Dashboard (visão geral)',
				'slug' => 'dashboard',
				'resumo' => 'Entender os gráficos e atalhos da página inicial.',
				'ordem' => 15,
				'corpo' => self::wrap(
					'O Dashboard resume a operação da escola em um só lugar: indicadores, gráficos e caminhos rápidos para o dia a dia.',
					[
						'Abra o menu <strong>Dashboard</strong> (ou clique no logo / painel).',
						'Confira os cartões e gráficos disponíveis no seu perfil (podem variar conforme permissões).',
						'Use os atalhos para ir a CRM, matrículas, carnês ou WhatsApp quando aparecerem.',
						'Os gráficos respeitam o tema claro/escuro do painel.',
					],
					'<ul><li>Se algum indicador parecer zerado, confira se há dados no período e se o módulo está liberado.</li></ul>',
					'Menu → Dashboard'
				),
			],
			[
				'cat' => 'primeiros-passos',
				'titulo' => 'Permissões e níveis de acesso',
				'slug' => 'permissoes-acesso',
				'resumo' => 'Como o diretor libera módulos e o que cada perfil enxerga.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'O que aparece no menu depende de dois filtros: o plano da escola e as permissões do usuário logado.',
					[
						'O <strong>plano</strong> da escola (Master CTI) define quais módulos podem ser usados (ex.: WhatsApp, EAD, Redes sociais).',
						'Em <strong>Usuários → Funcionários</strong>, o diretor marca as permissões de cada pessoa no checklist.',
						'Módulos como <strong>Redes sociais</strong> exigem permissão explícita no usuário (não bastam só o plano).',
						'Diretor recebe automaticamente vários itens (Comunicação, Campanhas, WhatsApp, Assinatura, Dados da escola…).',
						'Se alguém não vê um menu, confira nesta ordem: plano da escola → checklist do usuário → aceite dos Termos.',
					],
					'<ul><li>Não compartilhe login entre funcionários.</li><li>Revise permissões ao mudar de função.</li><li>Relatórios CRM são exclusivos do Diretor (com permissão de Leads).</li></ul>',
					'Usuários → Funcionários'
				),
			],
			[
				'cat' => 'primeiros-passos',
				'titulo' => 'Termos de Uso e LGPD',
				'slug' => 'termos-lgpd',
				'resumo' => 'Aceite obrigatório no primeiro acesso e onde consultar depois.',
				'ordem' => 30,
				'corpo' => self::wrap(
					'O aceite dos Termos de Uso é obrigatório para usar o painel, alinhado à LGPD e às regras da plataforma CTI.',
					[
						'No primeiro login, leia o texto completo e clique em aceitar.',
						'Depois, você pode consultar em <strong>Termos de Uso</strong> no menu.',
						'Cadastre dados de alunos, responsáveis e leads com cuidado: use só o necessário para a operação da escola.',
						'Quando a CTI publicar uma nova versão dos termos, o sistema pode pedir novo aceite.',
					],
					'<ul><li>Sem o aceite, o acesso aos módulos fica bloqueado e você é direcionado à tela de Termos.</li></ul>',
					'Menu → Termos de Uso'
				),
			],
			[
				'cat' => 'primeiros-passos',
				'titulo' => 'Perfil e senha',
				'slug' => 'perfil-senha',
				'resumo' => 'Atualizar dados pessoais, foto e senha do usuário.',
				'ordem' => 40,
				'corpo' => self::wrap(
					'Cada usuário gerencia o próprio perfil: nome, contato, foto e senha.',
					[
						'Abra <strong>Perfil</strong> no menu (ou pelo atalho do usuário no topo).',
						'Atualize nome, e-mail e demais campos disponíveis.',
						'Altere a senha quando necessário (use senha forte e pessoal).',
						'Envie foto de perfil se a tela permitir.',
					],
					'<ul><li>Não use e-mails fictícios.</li><li>Em caso de esquecimento de senha, use a recuperação na tela de login.</li></ul>',
					'Menu → Perfil'
				),
			],
		];
	}

	private static function artUsuarios(): array {
		return [
			[
				'cat' => 'usuarios-cadastros',
				'titulo' => 'Cadastrar e gerenciar funcionários',
				'slug' => 'funcionarios',
				'resumo' => 'Criar usuários internos, senha, permissões e status.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'Funcionários são usuários internos do painel (secretaria, comercial, financeiro etc.).',
					[
						'Abra <strong>Usuários → Funcionários</strong>.',
						'Clique em novo cadastro e preencha nome, e-mail válido e senha.',
						'Marque as <strong>permissões</strong> conforme a função (CRM, Carnês, WhatsApp…).',
						'Salve e peça para a pessoa fazer o primeiro login e aceitar os Termos.',
						'Para desativar acesso, edite o usuário e ajuste o status conforme a tela.',
					],
					'<ul><li>E-mail é obrigatório e não pode ser fictício.</li><li>Prefira um usuário por pessoa.</li></ul>',
					'Usuários → Funcionários'
				),
			],
			[
				'cat' => 'usuarios-cadastros',
				'titulo' => 'Cadastro de alunos',
				'slug' => 'alunos',
				'resumo' => 'Incluir alunos, dados de contato, responsável e observações.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'Alunos são o núcleo pedagógico e financeiro da escola.',
					[
						'Abra <strong>Usuários → Alunos</strong>.',
						'Cadastre nome, documentos, contatos e vínculo com responsável (quando houver).',
						'Informe e-mail e WhatsApp válidos para cobrança e comunicação.',
						'Use observações para anotações internas.',
						'Depois do cadastro, vincule o aluno a uma <strong>matrícula</strong> na trilha desejada.',
					],
					'<ul><li>E-mail opcional, mas se preencher deve ser real.</li></ul>',
					'Usuários → Alunos'
				),
			],
			[
				'cat' => 'usuarios-cadastros',
				'titulo' => 'Cadastro de responsáveis',
				'slug' => 'responsaveis',
				'resumo' => 'Pais/responsáveis financeiros e de contato.',
				'ordem' => 30,
				'corpo' => self::wrap(
					'Responsáveis recebem cobranças e comunicações quando a escola assim define.',
					[
						'Abra <strong>Usuários → Responsáveis</strong>.',
						'Cadastre nome, telefone, e-mail e documentos.',
						'Vincule o responsável aos alunos correspondentes.',
						'Confira e-mail/WhatsApp antes de disparar campanhas ou carnês.',
					],
					'',
					'Usuários → Responsáveis'
				),
			],
		];
	}

	private static function artPedagogico(): array {
		return [
			[
				'cat' => 'pedagogico',
				'titulo' => 'Trilhas e categorias de curso',
				'slug' => 'trilhas-categorias',
				'resumo' => 'Estrutura comercial dos cursos presenciais/contratos.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'Trilhas representam os cursos/planos comerciais da escola (contrato e carnê). São diferentes dos cursos EAD.',
					[
						'Em <strong>Pedagógico → Categorias</strong>, organize as áreas.',
						'Em <strong>Pedagógico → Trilhas</strong>, crie ou edite a trilha (nome, valores, status ativo).',
						'Mantenha só trilhas ativas disponíveis para novas matrículas.',
					],
					'<ul><li>Trilha = comercial. Curso Online (EAD) = portal Ascend.</li></ul>',
					'Pedagógico → Categorias / Trilhas'
				),
			],
			[
				'cat' => 'pedagogico',
				'titulo' => 'Matrículas',
				'slug' => 'matriculas',
				'resumo' => 'Matricular aluno em trilha, status e vínculo financeiro.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'A matrícula liga o aluno a uma trilha e ao fluxo comercial.',
					[
						'Abra <strong>Pedagógico → Matriculas</strong>.',
						'Selecione o aluno e a trilha desejada.',
						'Confira datas, valores e status da matrícula.',
						'Gere ou vincule o carnê/contrato conforme o processo da escola.',
					],
					'<ul><li>Matrícula comercial não libera sozinha o EAD.</li></ul>',
					'Pedagógico → Matriculas'
				),
			],
			[
				'cat' => 'pedagogico',
				'titulo' => 'Certificações',
				'slug' => 'certificacoes',
				'resumo' => 'Emitir e gerenciar certificados da escola.',
				'ordem' => 30,
				'corpo' => self::wrap(
					'O módulo de certificações gera documentos de conclusão.',
					[
						'Abra <strong>Pedagógico → Certificações</strong>.',
						'Localize o aluno/curso elegível.',
						'Emita o certificado e faça o download/impressão.',
						'Confira dados da escola em Configurações se algo estiver desatualizado.',
					],
					'',
					'Pedagógico → Certificações'
				),
			],
		];
	}

	private static function artEad(): array {
		return [
			[
				'cat' => 'portal-ead',
				'titulo' => 'Cursos Online (EAD) — visão geral',
				'slug' => 'cursos-online-ead',
				'resumo' => 'Criar cursos, módulos e publicar no portal Ascend.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'Cursos Online são independentes das trilhas comerciais. O aluno estuda no portal Ascend.',
					[
						'Abra <strong>Portal EAD → Cursos Online</strong>.',
						'Crie um curso com título, descrição e status (rascunho/publicado).',
						'Entre no editor do curso para montar módulos e aulas.',
						'Publique o curso quando o conteúdo estiver pronto.',
						'Matricule alunos na aba de alunos do curso (matrícula EAD).',
					],
					'<ul><li>Sem matrícula EAD ativa + curso publicado, o aluno não vê o conteúdo.</li></ul>',
					'Portal EAD → Cursos Online'
				),
			],
			[
				'cat' => 'portal-ead',
				'titulo' => 'Editor de curso: aulas, vídeos e materiais',
				'slug' => 'editor-curso-aulas',
				'resumo' => 'Montar currículo: módulos, aulas, vídeo Bunny, materiais e atividades.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'O editor organiza o currículo do curso EAD.',
					[
						'Abra o curso e entre no editor.',
						'Crie <strong>módulos</strong> na ordem desejada.',
						'Adicione <strong>aulas</strong> (vídeo, texto, material, atividade, roleplay etc.).',
						'Vídeos usam a integração <strong>Bunny Stream</strong>.',
						'Salve e teste no portal do aluno com uma matrícula de teste.',
					],
					'',
					'Portal EAD → Cursos Online → editor'
				),
			],
			[
				'cat' => 'portal-ead',
				'titulo' => 'Vitrine de cursos',
				'slug' => 'vitrine-cursos',
				'resumo' => 'Licenciar cursos de outras escolas e gerenciar assinatura da vitrine.',
				'ordem' => 30,
				'corpo' => self::wrap(
					'A vitrine permite consumir cursos compartilhados por outras escolas.',
					[
						'Abra <strong>Portal EAD → Vitrine de cursos</strong>.',
						'Veja cursos disponíveis para licenciar.',
						'Contrate/ative a licença conforme o fluxo financeiro.',
						'Após ativa, o curso aparece para matrícula EAD na sua escola.',
					],
					'<ul><li>O menu só aparece se houver oferta ou licença ativa.</li></ul>',
					'Portal EAD → Vitrine de cursos'
				),
			],
			[
				'cat' => 'portal-ead',
				'titulo' => 'Progresso e alunos online',
				'slug' => 'progresso-ead',
				'resumo' => 'Acompanhar turma, % concluído, liberar aula e alunos conectados.',
				'ordem' => 40,
				'corpo' => self::wrap(
					'Acompanhe o andamento da turma no EAD.',
					[
						'Em <strong>Progresso EAD</strong>, filtre por curso e status.',
						'Abra o detalhe do aluno para ver histórico de aulas.',
						'Use <strong>Liberar próxima aula</strong> quando o avanço for manual.',
						'Em <strong>Alunos online</strong>, veja quem está ativo.',
						'Exporte CSV quando precisar reportar.',
					],
					'',
					'Portal EAD → Progresso EAD / Alunos online'
				),
			],
			[
				'cat' => 'portal-ead',
				'titulo' => 'Conquistas EAD',
				'slug' => 'conquistas-ead',
				'resumo' => 'Badges e gamificação do portal do aluno.',
				'ordem' => 50,
				'corpo' => self::wrap(
					'Conquistas reforçam engajamento (badges, XP, streaks).',
					[
						'Abra <strong>Portal EAD → Conquistas EAD</strong> (se liberado).',
						'Cadastre ou revise conquistas disponíveis.',
						'Oriente os alunos a acompanharem o progresso no Ascend.',
					],
					'',
					'Portal EAD → Conquistas EAD'
				),
			],
		];
	}

	private static function artCrm(): array {
		return [
			[
				'cat' => 'crm-leads',
				'titulo' => 'CRM: funil e leads',
				'slug' => 'crm-leads-kanban',
				'resumo' => 'Cadastrar leads, mover no funil e registrar histórico.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'O CRM organiza o comercial: do interesse até a matrícula, com Kanban por status e histórico de contatos.',
					[
						'Abra <strong>CRM → Leads</strong>.',
						'Cadastre um lead (nome, WhatsApp, curso de interesse, origem, valor estimado).',
						'Altere o status no funil: <em>novo</em>, <em>em atendimento</em>, <em>matriculado</em>, <em>perdido</em>.',
						'Abra o detalhe do lead para editar dados, ver histórico e comentar.',
						'Importe planilha quando tiver muitos leads de uma vez.',
						'Use o botão WhatsApp no lead para abrir o Inbox (se conectado) ou o WhatsApp Web.',
					],
					'<ul><li>WhatsApp do lead deve ser válido para abrir conversa.</li><li>Ao mudar status, pode haver mensagem automática — configure em <strong>CRM → Automação WhatsApp</strong> (Diretor).</li><li>Em perda, informe o motivo quando a tela pedir.</li></ul>',
					'CRM → Leads'
				),
			],
			[
				'cat' => 'crm-leads',
				'titulo' => 'Tarefas do CRM',
				'slug' => 'crm-tarefas',
				'resumo' => 'Kanban de tarefas (estilo Trello) para follow-up da equipe.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'Tarefas ajudam a organizar follow-ups e pendências do time comercial/secretaria em quadros com listas e cards.',
					[
						'Abra <strong>CRM → Tarefas</strong>.',
						'Crie ou renomeie listas (colunas do quadro).',
						'Adicione cards com título e descrição.',
						'Arraste cards entre listas conforme o andamento.',
						'Use checklists e comentários nos cards quando disponíveis.',
					],
					'<ul><li>Combine com o Kanban de leads: lead no funil + tarefa de follow-up.</li></ul>',
					'CRM → Tarefas'
				),
			],
			[
				'cat' => 'crm-leads',
				'titulo' => 'Relatórios CRM (Diretor)',
				'slug' => 'relatorios-crm',
				'resumo' => 'KPIs de leads, funis, conversão, perdas e origens.',
				'ordem' => 30,
				'corpo' => self::wrap(
					'Relatórios gerenciais do CRM, exclusivos do <strong>Diretor</strong>, com filtro por período de cadastro dos leads.',
					[
						'Abra <strong>CRM → Relatórios CRM</strong>.',
						'Escolha o período (De / Até) e clique em Filtrar.',
						'Analise os KPIs: total de leads, matriculados, perdidos, % de conversão e valor estimado.',
						'Veja a distribuição <strong>por status</strong> e <strong>por funil</strong>.',
						'Confira motivos de perda e as principais origens.',
					],
					'<ul><li>Só o Diretor vê este menu (com permissão de Leads).</li><li>Relatório detalhado de tarefas Kanban ainda está no roadmap.</li></ul>',
					'CRM → Relatórios CRM'
				),
			],
		];
	}

	private static function artWhatsapp(): array {
		return [
			[
				'cat' => 'whatsapp',
				'titulo' => 'Conectar WhatsApp',
				'slug' => 'whatsapp-conexao',
				'resumo' => 'Parear o número da escola via QR Code.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'O WhatsApp da escola conecta via Evolution API.',
					[
						'Abra o módulo <strong>WhatsApp</strong>.',
						'Inicie a conexão e escaneie o <strong>QR Code</strong> com o WhatsApp da escola.',
						'Aguarde o status conectado e faça um teste de mensagem.',
						'Se desconectar, reconecte pelo mesmo fluxo.',
					],
					'<ul><li>Use um número dedicado da escola.</li></ul>',
					'WhatsApp'
				),
			],
			[
				'cat' => 'whatsapp',
				'titulo' => 'Inbox, setores e atendimento humano',
				'slug' => 'whatsapp-inbox',
				'resumo' => 'Assumir conversas, transferir setor e responder clientes.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'O inbox concentra as conversas com clientes e leads.',
					[
						'Abra <strong>WhatsApp</strong>.',
						'Veja conversas abertas, na fila ou atribuídas.',
						'<strong>Assuma</strong> um atendimento para responder como humano.',
						'Transfira para outro <strong>setor</strong> quando necessário.',
						'Cadastre setores e atendentes (Diretor) na área do WhatsApp.',
					],
					'<ul><li>Em atendimento humano, o bot não interfere.</li></ul>',
					'WhatsApp → Inbox'
				),
			],
			[
				'cat' => 'whatsapp',
				'titulo' => 'Fluxos do bot (automações)',
				'slug' => 'whatsapp-fluxos-bot',
				'resumo' => 'Templates, gatilhos, simulador, lead CRM e timeout.',
				'ordem' => 30,
				'corpo' => self::wrap(
					'Fluxos configuráveis respondem automaticamente por palavra-chave, saudação ou primeira mensagem.',
					[
						'Em WhatsApp, abra <strong>Fluxos do bot</strong>.',
						'Escolha um <strong>template pronto</strong> ou crie do zero.',
						'Defina o <strong>gatilho</strong> e a prioridade.',
						'Monte os passos: texto, pergunta, opções, condição, delay, lead CRM, setor, humano, fim.',
						'Use o <strong>simulador</strong> antes de ativar.',
						'Digite <em>sair</em> encerra o bot; <em>menu</em> volta ao menu de setores.',
					],
					'<ul><li>Não ative dois fluxos com o mesmo gatilho ao mesmo tempo.</li><li>Se nenhum fluxo casar, vale o menu clássico de setores.</li></ul>',
					'WhatsApp → Fluxos'
				),
			],
		];
	}

	private static function artSocial(): array {
		return [
			[
				'cat' => 'redes-sociais',
				'titulo' => 'Conectar Facebook e Instagram (Meta)',
				'slug' => 'conectar-meta',
				'resumo' => 'OAuth da Página e Instagram Professional.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'A publicação nas redes exige conexão Meta (diretor + permissão Redes sociais).',
					[
						'Vá em <strong>Configurações → Conexão Meta</strong>.',
						'Inicie o login OAuth e autorize a Página e o Instagram Professional.',
						'Confirme que a conexão aparece como ativa.',
						'Depois use o módulo <strong>Redes sociais</strong> para agendar posts.',
					],
					'<ul><li>É necessário Instagram profissional vinculado à Página.</li></ul>',
					'Configurações → Conexão Meta'
				),
			],
			[
				'cat' => 'redes-sociais',
				'titulo' => 'Agendar Feed, Story, Reel e Carrossel',
				'slug' => 'agendar-posts-social',
				'resumo' => 'Biblioteca, agenda semanal/mensal e histórico de publicações.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'O módulo Redes sociais agenda conteúdos para Facebook/Instagram.',
					[
						'Abra <strong>Redes sociais</strong>.',
						'Faça upload na biblioteca ou anexe mídia ao criar o post.',
						'Escolha o formato: Feed, Story, Reel ou Carrossel.',
						'Defina data/hora na visão semana ou mês.',
						'Acompanhe o status e o histórico de publicação.',
					],
					'<ul><li>Sem conexão Meta ativa, o agendamento não publica.</li></ul>',
					'Menu → Redes sociais'
				),
			],
		];
	}

	private static function artCampanhas(): array {
		return [
			[
				'cat' => 'campanhas-email',
				'titulo' => 'Configurar e-mail (SMTP) da escola',
				'slug' => 'comunicacao-smtp',
				'resumo' => 'Remetente, SMTP e teste de envio.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'Sem SMTP válido, campanhas e cobranças por e-mail não saem.',
					[
						'Abra <strong>Configurações → Comunicação</strong>.',
						'Preencha host, porta, usuário, senha e e-mail remetente.',
						'Salve e envie um <strong>e-mail de teste</strong>.',
						'Use o auditor de e-mails para limpar cadastros inválidos.',
					],
					'<ul><li>Prefira e-mail profissional do domínio da escola.</li></ul>',
					'Configurações → Comunicação'
				),
			],
			[
				'cat' => 'campanhas-email',
				'titulo' => 'Campanhas de e-mail',
				'slug' => 'campanhas-email',
				'resumo' => 'Segmentar público, montar mensagem e disparar.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'Campanhas enviam comunicados para alunos, responsáveis ou leads.',
					[
						'Abra <strong>Campanhas</strong>.',
						'Crie uma campanha e escolha o segmento.',
						'Redija o assunto e o corpo.',
						'Agende ou dispare e acompanhe o status.',
					],
					'<ul><li>E-mails fake são bloqueados pelo validador.</li></ul>',
					'Menu → Campanhas'
				),
			],
			[
				'cat' => 'campanhas-email',
				'titulo' => 'Cobrança automática por e-mail',
				'slug' => 'cobranca-automatica',
				'resumo' => 'Avisos antes/no dia/atraso da mensalidade.',
				'ordem' => 30,
				'corpo' => self::wrap(
					'A cobrança automática lembra mensalidades por e-mail.',
					[
						'Confirme que o SMTP está ok em Comunicação.',
						'Verifique carnês/títulos no Financeiro.',
						'Os disparos rodam pelo worker de cobrança.',
						'Monitore resultados e atualize e-mails inválidos.',
					],
					'',
					'Financeiro + Configurações → Comunicação'
				),
			],
		];
	}

	private static function artFinanceiro(): array {
		return [
			[
				'cat' => 'financeiro',
				'titulo' => 'Assinatura do Painel (SaaS)',
				'slug' => 'assinatura-saas',
				'resumo' => 'Faturas da plataforma CTI, PIX e situação da escola.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'A assinatura SaaS é o pagamento do próprio Painel CTI (não confundir com carnê de aluno).',
					[
						'Abra <strong>Financeiro → Assinatura</strong>.',
						'Veja faturas em aberto e o status da escola.',
						'Pague via PIX quando disponível.',
					],
					'',
					'Financeiro → Assinatura'
				),
			],
			[
				'cat' => 'financeiro',
				'titulo' => 'Carnês e cobrança de alunos',
				'slug' => 'carnes-pix',
				'resumo' => 'Gerar carnês, acompanhar parcelas e PIX (Mercado Pago).',
				'ordem' => 20,
				'corpo' => self::wrap(
					'Carnês controlam as mensalidades dos alunos.',
					[
						'Abra <strong>Financeiro → Carnês</strong>.',
						'Localize o aluno/matrícula e gere ou abra o carnê.',
						'Acompanhe parcelas pagas, abertas e atrasadas.',
						'Se usa Mercado Pago, o aluno pode pagar via PIX.',
					],
					'<ul><li>Configure credenciais em Configurações → Pagamentos.</li></ul>',
					'Financeiro → Carnês'
				),
			],
			[
				'cat' => 'financeiro',
				'titulo' => 'Caixa: entradas e saídas',
				'slug' => 'caixa-entrada-saida',
				'resumo' => 'Lançar movimentos do caixa da escola.',
				'ordem' => 30,
				'corpo' => self::wrap(
					'Entradas e saídas registram o fluxo de caixa operacional.',
					[
						'Em <strong>Financeiro → Entrada</strong>, lance recebimentos avulsos.',
						'Em <strong>Saída</strong>, registre despesas.',
						'Informe valor, data e descrição.',
						'Use Relatórios para consolidar o período.',
					],
					'',
					'Financeiro → Entrada / Saída'
				),
			],
			[
				'cat' => 'financeiro',
				'titulo' => 'Relatórios financeiros',
				'slug' => 'relatorios-financeiros',
				'resumo' => 'Visão consolidada de caixa e indicadores.',
				'ordem' => 40,
				'corpo' => self::wrap(
					'Relatórios ajudam a fechar o mês e acompanhar entradas/saídas do caixa da escola.',
					[
						'Abra <strong>Financeiro → Relatórios</strong> (caminho do caixa/relatório).',
						'Filtre o período desejado.',
						'Analise entradas, saídas e saldos conforme os totais da tela.',
						'Exporte ou imprima quando a tela oferecer a opção.',
					],
					'<ul><li>Não confundir com Relatórios CRM (comercial) nem com Assinatura SaaS (mensalidade do painel).</li></ul>',
					'Financeiro → Relatórios'
				),
			],
			[
				'cat' => 'financeiro',
				'titulo' => 'Pagamentos (Mercado Pago da escola)',
				'slug' => 'pagamentos-mercadopago',
				'resumo' => 'Credenciais PIX/MP para carnês dos alunos.',
				'ordem' => 50,
				'corpo' => self::wrap(
					'As credenciais Mercado Pago da escola habilitam PIX nos carnês.',
					[
						'Abra <strong>Configurações → Pagamentos</strong> (Diretor).',
						'Informe as chaves conforme o guia da tela.',
						'Salve e teste um pagamento em ambiente controlado.',
					],
					'',
					'Configurações → Pagamentos'
				),
			],
		];
	}

	private static function artVendas(): array {
		return [
			[
				'cat' => 'vendas-estoque',
				'titulo' => 'Estoque de produtos',
				'slug' => 'estoque',
				'resumo' => 'Cadastrar produtos, quantidades e movimentações.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'O estoque controla materiais e produtos vendidos na escola.',
					[
						'Abra <strong>Vendas → Estoque</strong>.',
						'Cadastre produtos com nome, preço e quantidade.',
						'Ajuste entradas/saídas conforme a operação.',
					],
					'',
					'Vendas → Estoque'
				),
			],
			[
				'cat' => 'vendas-estoque',
				'titulo' => 'PDV (ponto de venda)',
				'slug' => 'pdv',
				'resumo' => 'Vender produtos do estoque no balcão.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'O PDV registra vendas rápidas vinculadas ao estoque.',
					[
						'Abra <strong>Vendas → PDV</strong>.',
						'Adicione os itens à venda.',
						'Confirme o pagamento e finalize.',
					],
					'',
					'Vendas → PDV'
				),
			],
		];
	}

	private static function artAgenda(): array {
		return [
			[
				'cat' => 'agenda',
				'titulo' => 'Laboratórios e horários',
				'slug' => 'laboratorios-horarios',
				'resumo' => 'Cadastro de salas/labs e grade de horários.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'Laboratórios e horários estruturam a agenda presencial.',
					[
						'Em <strong>Agenda → Laboratórios</strong>, cadastre salas/labs.',
						'Em <strong>Horários</strong>, monte a grade.',
						'Associe aos agendamentos da turma.',
					],
					'',
					'Agenda → Laboratórios / Horários'
				),
			],
			[
				'cat' => 'agenda',
				'titulo' => 'Agendamentos e diário',
				'slug' => 'agendamentos-diario',
				'resumo' => 'Reservas de lab e registro diário de aulas.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'Agendamentos ocupam labs; o diário registra a aula do dia.',
					[
						'Abra <strong>Agenda → Agendamentos</strong> e crie a reserva.',
						'Evite conflito de horário no mesmo laboratório.',
						'No <strong>Diário</strong>, registre a aula/presença conforme o fluxo da escola.',
					],
					'',
					'Agenda → Agendamentos / Diário'
				),
			],
		];
	}

	private static function artConfig(): array {
		return [
			[
				'cat' => 'configuracoes',
				'titulo' => 'Dados da escola',
				'slug' => 'dados-escola',
				'resumo' => 'Razão social, endereço, logo e contatos.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'Dados cadastrais alimentam contratos, impressos e comunicação.',
					[
						'Abra <strong>Configurações → Dados da escola</strong> (Diretor).',
						'Atualize razão social, CNPJ, endereço e contatos.',
						'Envie o logo para impressos e documentos.',
					],
					'',
					'Configurações → Dados da escola'
				),
			],
			[
				'cat' => 'configuracoes',
				'titulo' => 'Modelo de contrato',
				'slug' => 'modelo-contrato',
				'resumo' => 'Texto-base do contrato da escola e frases do certificado.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'O modelo de contrato é personalizado por escola.',
					[
						'Abra <strong>Configurações → Modelo de contrato</strong>.',
						'Edite o texto-base e cláusulas.',
						'Salve e gere um contrato de teste.',
					],
					'',
					'Configurações → Modelo de contrato'
				),
			],
			[
				'cat' => 'configuracoes',
				'titulo' => 'Bunny Stream (vídeos EAD)',
				'slug' => 'bunny-stream',
				'resumo' => 'Biblioteca de vídeo para aulas online (conta global).',
				'ordem' => 30,
				'corpo' => self::wrap(
					'O Bunny Stream hospeda os vídeos das aulas EAD (conta única da plataforma). YouTube não é mais aceito.',
					[
						'Peça ao suporte CTI / Master para configurar em <strong>Master → Bunny</strong> (Stream + Storage).',
						'No editor do curso, anexe os vídeos às aulas pelo Bunny.',
						'No L-Editor, imagens/áudios vão para Storage; vídeos de cena para Stream.',
					],
					'',
					'Master → Bunny'
				),
			],
			[
				'cat' => 'configuracoes',
				'titulo' => 'Configurações de IA',
				'slug' => 'ia-pedagogica',
				'resumo' => 'Uma chave compartilhada: pedagógica (EAD), Assistente (Telegram) e variação WhatsApp.',
				'ordem' => 40,
				'corpo' => self::wrap(
					'Em <strong>Configurações de IA</strong> você cadastra <strong>uma</strong> chave (provedor + modelo + API key) e liga os recursos liberados no plano.',
					[
						'Abra <strong>Configurações → Configurações de IA</strong> (Diretor).',
						'Preencha provedor, modelo e API key (compartilhados).',
						'Se o plano tiver EAD: ative <strong>IA Pedagógica</strong> para tutor/role play no portal do aluno.',
						'Se o plano tiver Assistente IA: ative o Assistente no Telegram, informe o bot e o Chat ID autorizado.',
						'Se o plano tiver WhatsApp: opcionalmente ative <strong>Variar textos das campanhas</strong>.',
					],
					'<p>O Assistente no Telegram é nativo do painel — não depende de serviços externos além do próprio Telegram.</p>',
					'Configurações → Configurações de IA'
				),
			],
		];
	}

	private static function artAssistenteIa(): array {
		return [
			[
				'cat' => 'assistente-ia',
				'titulo' => 'O que é o Assistente IA',
				'slug' => 'assistente-ia-visao-geral',
				'resumo' => 'Bot Telegram nativo que consulta dados da escola em modo somente leitura.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'O <strong>Assistente IA</strong> é um bot do Telegram integrado ao painel. Ele consulta agenda, inadimplentes, CRM, WhatsApp e outros indicadores — sempre em modo somente leitura.',
					[
						'O módulo precisa estar liberado no <strong>plano</strong> da escola (slug <code>assistente_ia</code>).',
						'O Diretor configura tudo em <strong>Configurações → Configurações de IA</strong>: chave compartilhada, bot do Telegram e Chat ID autorizado.',
						'Por padrão o bot responde com <strong>palavras-chave</strong> (sem gastar tokens). A IA em perguntas livres é opcional.',
						'Independente da <strong>IA Pedagógica</strong> do portal EAD (mesmo que compartilhem a mesma API key).',
					],
					'<p><strong>Segurança:</strong> só os Chat IDs cadastrados recebem resposta. Não compartilhe o token do bot.</p>',
					'Configurações → Configurações de IA'
				),
			],
			[
				'cat' => 'assistente-ia',
				'titulo' => 'Configurar IA e Telegram (Diretor)',
				'slug' => 'assistente-ia-configurar-escola',
				'resumo' => 'Como o Diretor liga o bot nativo: credenciais, token e Chat ID.',
				'ordem' => 20,
				'corpo' => self::wrap(
					'Nesta tela você ativa o Assistente e cadastra o bot do Telegram com segurança.',
					[
						'Abra <strong>Configurações → Configurações de IA</strong> (Diretor, com o módulo no plano).',
						'Em <strong>Credenciais compartilhadas</strong>: provedor, modelo e API key.',
						'Ative <strong>Ativar Assistente no Telegram</strong>.',
						'Opcional: ligue <strong>Usar IA em perguntas livres</strong> (gasta tokens). Desligado = só /resumo, /agenda, etc.',
						'Crie o bot no <code>@BotFather</code>, cole o token, o username e o <strong>Chat ID autorizado</strong> (obrigatório; use @userinfobot).',
						'Clique em <strong>Salvar</strong>, depois <strong>Enviar teste</strong>. Em produção HTTPS: <strong>Ativar webhook</strong>.',
					],
					'<ul><li>Não compartilhe tokens em grupos ou WhatsApp.</li><li>Em XAMPP (sem HTTPS), use o worker <code>php worker/telegram_agent.php</code>.</li></ul>',
					'Configurações → Configurações de IA'
				),
			],
			[
				'cat' => 'assistente-ia',
				'titulo' => 'Uma tela de IA, vários recursos',
				'slug' => 'assistente-ia-vs-pedagogica',
				'resumo' => 'Credenciais únicas com toggles por módulo do plano.',
				'ordem' => 30,
				'corpo' => self::wrap(
					'O painel usa <strong>uma</strong> configuração de chave de IA, com interruptores por recurso liberado no plano.',
					[
						'<strong>IA Pedagógica</strong>: tutor e role play no <strong>portal do aluno (EAD)</strong>.',
						'<strong>Assistente IA</strong>: bot Telegram nativo (consulta agenda, financeiro, CRM).',
						'<strong>Variar textos WhatsApp</strong>: anti-template em campanhas.',
						'Tudo em <em>Configurações → Configurações de IA</em>.',
					],
					'<p>Ativar só a pedagógica <strong>não</strong> liga o bot do Telegram — e vice-versa.</p>',
					'Configurações → Configurações de IA'
				),
			],
			[
				'cat' => 'assistente-ia',
				'titulo' => 'Segurança do bot e Chat ID',
				'slug' => 'assistente-ia-ativacao-seguranca',
				'resumo' => 'Allowlist de chats, token do bot e boas práticas.',
				'ordem' => 40,
				'corpo' => self::wrap(
					'O Assistente só responde a chats autorizados. Isso protege os dados da escola.',
					[
						'Cadastre o <strong>Chat ID</strong> em Configurações de IA (vários IDs separados por vírgula).',
						'Descubra o ID falando com <code>@userinfobot</code> (ou equivalente) no Telegram.',
						'O token do bot fica criptografado no painel — nunca envie por WhatsApp.',
						'Em produção, use <strong>webhook HTTPS</strong>. Em local, use o worker long-poll.',
						'Para pausar: desative <strong>Ativar Assistente no Telegram</strong> e salve (ou remova o webhook).',
					],
					'<ul><li>Não reutilize o mesmo bot em duas escolas.</li><li>Se suspeitar de vazamento do token, revogue no @BotFather e cadastre um novo.</li></ul>',
					'Configurações → Configurações de IA'
				),
			],
			[
				'cat' => 'assistente-ia',
				'titulo' => 'O que o Assistente pode consultar',
				'slug' => 'assistente-ia-consultas',
				'resumo' => 'Consultas somente leitura: agenda, inadimplentes, CRM, matrículas, WhatsApp e mais.',
				'ordem' => 50,
				'corpo' => self::wrap(
					'O Assistente é <strong>somente leitura</strong>: analisa e responde; não dá baixa, não altera matrícula e não envia WhatsApp pelo painel.',
					[
						'<strong>Resumo do dia:</strong> matrículas ativas, recebido hoje, a receber na semana, CRM e indicadores rápidos.',
						'<strong>Agenda:</strong> aulas/agendamentos de hoje.',
						'<strong>Financeiro:</strong> inadimplentes (mês/semana/hoje) e títulos a receber.',
						'<strong>CRM:</strong> leads e conversão no período.',
						'<strong>Matrículas:</strong> ativas e novas no mês.',
						'<strong>WhatsApp:</strong> fila, não lidas e conversas abertas (quando o módulo existir).',
					],
					'<p>Comandos sem IA: <code>/resumo</code>, <code>/agenda</code>, <code>/ajuda</code> e outros listados no próprio bot.</p>',
					'Telegram do Assistente IA'
				),
			],
		];
	}

	private static function artSuporte(): array {
		return [
			[
				'cat' => 'suporte',
				'titulo' => 'Abrir chamado de suporte',
				'slug' => 'abrir-chamado-suporte',
				'resumo' => 'Falar com a equipe CTI: categorias, anexos e status.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'O módulo Suporte é o canal oficial com a equipe CTI.',
					[
						'Abra <strong>Suporte</strong> no menu.',
						'Clique em novo chamado e escolha a categoria.',
						'Descreva o problema com passos para reproduzir.',
						'Anexe um print (imagem até 5 MB) se ajudar.',
						'Acompanhe respostas na thread. Status resolvido/fechado encerra a conversa daquele chamado.',
					],
					'<ul><li>Número do chamado no formato CHM-ANO-00000.</li></ul>',
					'Menu → Suporte'
				),
			],
			[
				'cat' => 'suporte',
				'titulo' => 'Como usar esta Central de Ajuda',
				'slug' => 'central-ajuda',
				'resumo' => 'Navegar tutoriais e vídeos (quando disponíveis).',
				'ordem' => 20,
				'corpo' => self::wrap(
					'A Central de Ajuda reúne tutoriais por tema. Também existe versão pública em /ajuda.',
					[
						'Abra <strong>Ajuda</strong> no menu ou /ajuda sem login.',
						'Escolha a categoria e o artigo.',
						'Quando houver vídeo, ele aparece no topo do artigo.',
						'Se ainda faltar informação, abra um chamado em Suporte.',
					],
					'',
					'Menu → Ajuda'
				),
			],
		];
	}

	private static function artPortalAluno(): array {
		return [
			[
				'cat' => 'portal-aluno',
				'titulo' => 'Portal Ascend (aluno)',
				'slug' => 'portal-ascend',
				'resumo' => 'Login do aluno, cursos, finanças e ranking.',
				'ordem' => 10,
				'corpo' => self::wrap(
					'O Ascend é o portal do aluno para EAD e informações da escola.',
					[
						'O aluno acessa com as credenciais cadastradas pela escola.',
						'Vê apenas matrículas EAD ativas em cursos publicados.',
						'Pode continuar de onde parou, fazer atividades e acompanhar conquistas/ranking.',
						'A área financeira mostra títulos quando houver carnês.',
						'Problemas de login: a escola redefine no painel; falha da plataforma → Suporte.',
					],
					'',
					'Portal Ascend + Painel → Cursos Online'
				),
			],
		];
	}
}
