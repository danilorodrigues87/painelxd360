<?php 

namespace App\Controller\Admin;

use \App\Utils\View;
use \App\Session\User\Login as SessionUser;
use \App\Model\Entity\User;
use \App\Common\SystemModules;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\ModuleGateHelper;
use \App\Common\Helpers\UserFotoHelper;
use \App\Common\Helpers\BrandingHelper;

class Page {

	

	public static function getDefaultModules($termosAceito){

    $defaultModules = ["Termos de Uso"];

    if($termosAceito){
        $defaultModules[] = "Dashboard";
        $defaultModules[] = "Perfil";
        $defaultModules[] = "Ajuda";
        $defaultModules[] = "Suporte";
    }

    return $defaultModules;
}


	public static function getIdAdmin(){
		return SessionUser::getUserLogedData();
	}

	public static function getIdAdminInt(): int {
		return TenantHelper::getIdAdmin();
	}

	/**
	 * JSON seguro para listagens AJAX (itens + pagination).
	 * Evita resposta vazia/quebrada por UTF-8 inválido.
	 */
	public static function jsonLista(array $conteudo): string {
		$json = json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			return json_encode([
				'itens' => '<div class="alert alert-danger m-3">Erro ao montar a lista. Tente novamente.</div>',
				'pagination' => '',
			], JSON_UNESCAPED_UNICODE);
		}
		return $json;
	}


	// RETORNA O CONTEUDO (VIEW) ESTRUTURA GENERICA PAGINA PAINEL
	public static function getPage($title, $content, $menu = ''){
		$userLogedData = SessionUser::getUserLogedData();
		if ($userLogedData === null || !isset($userLogedData['usuario'])) {
			header('Location: '.URL.'/');
			exit;
		}

		$bannerImpersonate = '';
		if (SessionUser::isImpersonating()) {
			$info = SessionUser::getImpersonateInfo() ?: [];
			$nomeEscola = htmlspecialchars((string)($info['escola_nome'] ?? 'escola'), ENT_QUOTES, 'UTF-8');
			$bannerImpersonate = '<div class="alert alert-warning py-2 px-3 mb-0 rounded-0 text-center small">'
				.'<i class="fas fa-user-secret me-1"></i> Você está no suporte do cliente <strong>'.$nomeEscola.'</strong>. '
				.'<a class="alert-link fw-bold" href="'.URL.'/master/voltar">Voltar ao Painel Master</a>'
				.'</div>';
		}

		$uid = (int)($userLogedData['usuario']['id'] ?? 0);
		$fotoUser = UserFotoHelper::urlPadrao();
		if ($uid > 0 && User::temColunaFoto()) {
			$obFoto = User::getUser('id = '.$uid, null, 1, 'foto')->fetchObject(User::class);
			$fotoUser = UserFotoHelper::urlPublica($obFoto->foto ?? null);
		}

		return View::render('admin/page',[
			'title' => $title,
			'content' => $content,
			'menu' => $menu,
			'user' => $userLogedData['usuario']['nome'],
			'company' => $userLogedData['escola']['nome'] ?? '',
			'banner_impersonate' => $bannerImpersonate,
			'foto_url' => $fotoUser,
			'logo_url' => BrandingHelper::urlLogoXd360(true),
			'favicon_url' => BrandingHelper::urlFaviconXd360(),
			'pwa_apple_icon_url' => \App\Common\Helpers\PwaHelper::appleTouchIconUrl(),
			'onesignal_head' => \App\Common\Helpers\OneSignalHelper::htmlHeadSnippet(),
		]);
	}


public static function getMenu($currentSessionMenu, $permittedModules) {


    // LINKS DO MENU
    $links = '';

    // ITERA OS MODULOS
    foreach (SystemModules::getModules() as $hash => $module) {
        $includeModule = false;
        $subLinks = '';

        // Verifica se o módulo principal está na lista de itens permitidos
        if (in_array($module['label'], $permittedModules)) {
            $includeModule = true;
        }

        // Verifica se há subseções e se alguma delas está na lista de itens permitidos
        if (isset($module['subsections'])) {
            $userNivel = SessionUser::getUserLogedData()['usuario']['nivel'] ?? '';
            foreach ($module['subsections']['items'] as $subSection) {
                if (!empty($subSection['diretor_only']) && $userNivel !== 'Diretor') {
                    continue;
                }

                $labelsPermitidos = [(string)$subSection['label']];
                if (!empty($subSection['requires_label'])) {
                    $labelsPermitidos[] = (string)$subSection['requires_label'];
                }
                if (!empty($subSection['requires_any']) && is_array($subSection['requires_any'])) {
                    $labelsPermitidos = array_merge($labelsPermitidos, $subSection['requires_any']);
                }
                if ($subSection['label'] === 'Agendamentos') {
                    $labelsPermitidos[] = 'Laboratório';
                }

                $subPermitido = false;
                foreach ($labelsPermitidos as $labelPerm) {
                    if (in_array($labelPerm, $permittedModules)) {
                        $subPermitido = true;
                        break;
                    }
                }
                if ($subPermitido) {
                    $includeModule = true;

                    // Adiciona subseção ao subLinks
                    $subLinks .= View::render('admin/menu/sub_link', [
                        'label' => $subSection['label'],
                        'link' => $subSection['link']
                    ]);
                }
            }
        }

        // Renderiza o módulo principal se ele ou alguma de suas subseções estiver permitida
        if ($includeModule) {
            $isActive = $hash == $currentSessionMenu;
            $currentClass = $isActive ? 'active' : '';
            $expanded = $isActive ? 'true' : 'false';
            $showClass = $isActive ? 'show' : '';

            if ($subLinks) {
                $links .= View::render('admin/menu/dropdown', [
                    'label' => $module['label'],
                    'icon' => $module['icon'],
                    'subLinks' => $subLinks,
                    'name' => $module['subsections']['name'] ?? '',
                    'current' => $currentClass,
                    'expanded' => $expanded,
                    'show' => $showClass
                ]);
            } elseif (!empty($module['link'])) {
                $links .= View::render('admin/menu/link', [
                    'label' => $module['label'],
                    'link' => $module['link'],
                    'icon' => $module['icon'],
                    'current' => $currentClass
                ]);
            }
        }
    }

    // RETORNA A RENDERIZAÇÃO DO MENU
    return View::render('admin/menu/box', [
        'links' => $links
    ]);
}






	// RENDERIZA A VIEW DO PAINEL COM CONTEUDO DINAMICO
	public static function getPanel($currentModule,$content,$currentSessionMenu,$request=null){

		$userLogedData = SessionUser::getUserLogedData();
		if ($userLogedData === null || !isset($userLogedData['usuario']['id'])) {
			if ($request instanceof \App\Http\Request && $request->getRouter()) {
				$request->getRouter()->redirect('/');
			}
			header('Location: '.URL.'/');
			exit;
		}

		// Master em impersonate: não bloquear por termos do diretor da escola
		$termosAceito = SessionUser::isImpersonating()
			|| TermosDeUso::usuarioAceitouVersaoAtual(
				User::getUser(
					'id = '.(int)$userLogedData['usuario']['id'],
					null,
					null,
					'termos_uso'.(\App\Model\Entity\User::temColunasTermosVersao() ? ', termos_versao' : '')
				)->fetchObject()
			);

		$permittedModules = array();


		if($termosAceito){
			$id_admin = (int)$userLogedData['usuario']['id_admin'];
			$acessoBruto = $userLogedData['usuario']['acesso'] ?? [];
			$permittedModules = ModuleGateHelper::getModulosEfetivos($id_admin, $acessoBruto);
		} 


		$defaultModules = self::getDefaultModules($termosAceito);

		$allPermittedModules = array_merge($defaultModules, $permittedModules);

		if (($userLogedData['usuario']['nivel'] ?? '') === 'Diretor') {
			$allPermittedModules[] = 'Dados da empresa';
			$allPermittedModules[] = 'Meus Produtos';
			$allPermittedModules[] = 'Assinatura';
			$allPermittedModules[] = 'Suporte';
		}

		// Vitrine: permissão (checklist ou Diretor com EAD no plano) + conteúdo útil
		$idAdminVitrine = (int)($userLogedData['usuario']['id_admin'] ?? 0);
		$slugsEscolaVitrine = ModuleGateHelper::getSlugsEscola($idAdminVitrine);
		$temPermVitrine = in_array('Vitrine de cursos', $allPermittedModules, true)
			|| in_array('Vitrine', $allPermittedModules, true);
		if (!$temPermVitrine
			&& ($userLogedData['usuario']['nivel'] ?? '') === 'Diretor'
			&& in_array('Cursos Online', $allPermittedModules, true)
			&& in_array('vitrine', $slugsEscolaVitrine, true)) {
			$temPermVitrine = true;
		}
		$allPermittedModules = array_values(array_filter(
			$allPermittedModules,
			static function ($l) {
				return $l !== 'Vitrine' && $l !== 'Vitrine de cursos';
			}
		));
		if ($temPermVitrine && \App\Common\Helpers\LmsVitrineHelper::deveExibirParaEscola($idAdminVitrine)) {
			$allPermittedModules[] = 'Vitrine de cursos';
		}

		$bloqueada = !empty($userLogedData['usuario']['assinatura_bloqueada']);
		if ($bloqueada) {
			$allPermittedModules = ['Assinatura'];
			$banner = '<div class="alert alert-danger mb-3">'
				.'<strong>Assinatura suspensa.</strong> O acesso está limitado a esta tela. '
				.'Regularize o pagamento do XD360 para liberar o sistema.'
				.'</div>';
			$content = $banner.$content;
		}

		$temAcesso = in_array($currentModule, $allPermittedModules);
		if(!$temAcesso && $currentModule === 'Agendamentos' && in_array('Laboratório', $allPermittedModules)){
			$temAcesso = true;
		}
		if(!$temAcesso && $currentModule === 'Relatório de presença' && in_array('Diário', $allPermittedModules)){
			$temAcesso = true;
		}
		if ($bloqueada && $currentModule === 'Assinatura') {
			$temAcesso = true;
		}

		if ($temAcesso) {

		$menuHtml = View::render('admin/panel',[
			'menu' => self::getMenu($currentSessionMenu,$allPermittedModules),
			'user' => $userLogedData['usuario']['nome'] ?? '',
		]);

		return self::getPage($currentModule, $content, $menuHtml);

	} else {
		$destino = '/painel/termos-de-uso';
		if ($termosAceito) {
			$destino = in_array('Dashboard', $allPermittedModules, true)
				? '/painel'
				: '/painel/produtos';
		}
		if ($request instanceof \App\Http\Request && $request->getRouter()) {
			$request->getRouter()->redirect($destino);
		}
		header('Location: '.URL.$destino);
		exit;
	}


	}


	private static function getPaginationLink($postVars, $page, $label = null) {
    $viewLink = '<li class="page-item ' . ($page['current'] ? 'active' : '') . '">
        <a class="page-link" href="#" onclick="irPagina('.(int)$page['page'].'); return false;">' . ($label ?? $page['page']) . '</a>
    </li>';
    return $viewLink;
}



// RENDERIZA O LAYOUT DE PAGINAÇÃO
	public static function getPagination($request, $obPagination) {
    // PÁGINAS
		$pages = $obPagination->getPages();

    // VERIFICA A QUANTIDADE DE PÁGINAS
		if (count($pages) <= 1) return '';

    // POST
		$postVars = $request->getPostVars();

    // PÁGINA ATUAL
		$currentPage = $postVars['page'] ?? 1;

    // LIMITE DE PÁGINA
		$limit = getenv('PAGINATION_LIMIT');

    // MEIO DA PAGINAÇÃO
		$middle = ceil($limit/2);

    // INÍCIO DA PAGINAÇÃO
		$start = $middle > $currentPage ? 0 : $currentPage - $middle;

    // AJUSTA O FINAL DA PAGINAÇÃO
		$limit = $limit + $start;

    // AJUSTA O INÍCIO DA PAGINAÇÃO
		if ($limit > count($pages)) {
			$diff = $limit - count($pages);
			$start = $start - $diff;
		}

    // LINKS DE PAGINAÇÃO
		$links = '';

    // LINK INICIAL
		if ($start > 0) {
			$links .= self::getPaginationLink($postVars, reset($pages), '<<');
		}

    // RENDERIZA OS ITENS
		foreach ($pages as $page) {
        // VERIFICA O START DA PAGINAÇÃO
			if ($page['page'] <= $start) continue;

        // VERIFICA O LIMITE DA PAGINAÇÃO
			if ($page['page'] > $limit) {
				$links .= self::getPaginationLink($postVars, end($pages), '>>');
				break;
			}

			$links .= self::getPaginationLink($postVars, $page);
		}

    // RENDERIZAÇÃO BOX DE PAGINAÇÃO
		$paginacao = 
		'<nav>
		<ul class="pagination">
		' . $links . '        
		</ul>
		</nav>';

		return $paginacao;
	}


}
