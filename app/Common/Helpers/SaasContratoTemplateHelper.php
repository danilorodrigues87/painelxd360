<?php

namespace App\Common\Helpers;

use App\Model\Entity\SaasContratoModelo;

class SaasContratoTemplateHelper {

	public static function catalogoVariaveis(): array {
		return [
			'URL'                   => 'URL base do painel (CSS/assets)',
			'licenciante'           => 'Bloco LICENCIANTE (XD360 — CNPJ, endereço, contatos)',
			'representante_licenciante' => 'Representante legal da XD360 (nome, CPF, cargo)',
			'licenciada'            => 'Bloco LICENCIADA (cliente contratante)',
			'representante_licenciada' => 'Administrador/representante legal do cliente',
			'plano_resumo'          => 'Plano, valor mensal e status da assinatura',
			'plano_descricao'       => 'Nome do plano + descrição detalhada dos produtos',
			'modulos_contratados'   => 'Lista de produtos liberados pelo plano',
			'condicoes_financeiras' => 'Mensalidade, vencimento, PIX, faturamento',
			'foro'                  => 'Comarca eleita (Cláusula 12ª)',
			'assinaturas'           => 'Bloco de assinaturas com nomes',
			'trial_dias'            => 'Dias padrão de trial (ex.: 14)',
			'grace_dias'            => 'Dias de tolerância após vencimento (ex.: 5)',
			'url_privacidade'       => 'Link da Política de Privacidade XD360',
			'data_contrato'         => 'Local, data por extenso',
		];
	}

	public static function modeloPadrao(): string {
		$path = __DIR__.'/../../../resources/view/master/modules/contrato_saas/modelo_padrao.html';
		$html = @file_get_contents($path);
		if ($html === false || trim($html) === '') {
			return self::modeloPadraoFallback();
		}
		return $html;
	}

	public static function resolverModelo(): string {
		if (SaasContratoModelo::tabelaExiste()) {
			$row = SaasContratoModelo::get();
			if ($row instanceof SaasContratoModelo) {
				$custom = trim((string)($row->html ?? ''));
				if ($custom !== '') {
					return $custom;
				}
			}
		}
		return self::modeloPadrao();
	}

	public static function aplicar(string $html, array $vars): string {
		$mapa = [];
		foreach ($vars as $k => $v) {
			$k = (string)$k;
			$val = (string)$v;
			$mapa['{{'.$k.'}}'] = $val;
			$mapa['{'.$k.'}'] = $val;
		}
		return str_replace(array_keys($mapa), array_values($mapa), $html);
	}

	public static function render(array $vars, ?string $htmlOverride = null): string {
		$html = ($htmlOverride !== null && trim($htmlOverride) !== '')
			? $htmlOverride
			: self::resolverModelo();
		return self::aplicar($html, $vars);
	}

	private static function modeloPadraoFallback(): string {
		return <<<'HTML'
<div class="bto"><button class="btn-impress" type="button" onclick="window.print()">Imprimir</button></div>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Contrato SaaS XD360</title>
<link rel="stylesheet" href="{{URL}}/resources/css/contrato.css"></head><body>
<div id="content">{{licenciante}}{{licenciada}}<p>Contrato de licença da plataforma XD360.</p>
{{plano_resumo}}{{modulos_contratados}}{{condicoes_financeiras}}{{data_contrato}}
</div></body></html>
HTML;
	}
}
