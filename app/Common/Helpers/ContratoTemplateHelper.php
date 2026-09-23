<?php

namespace App\Common\Helpers;

use App\Model\Entity\ClientesAssinantes;

/**
 * Template de contrato de matrícula por escola.
 * modelo_contrato_html NULL → padrão idêntico ao contrato atual (Capão Bonito / escola 1).
 */
class ContratoTemplateHelper {

	/** Placeholders aceitos no HTML do modelo ({{chave}} ou {chave}). */
	public static function catalogoVariaveis(): array {
		return [
			'URL'            => 'URL base do painel (CSS/assets)',
			'contratada'     => 'Bloco CONTRATADA (logo, escola, CNPJ, contato)',
			'contratante'    => 'Bloco CONTRATANTE/ALUNO (+ responsável se menor)',
			'curso'          => 'Formação, módulos, carga, datas, pagamento',
			'clausula_1'     => 'Cláusula 1ª (editada na categoria)',
			'clausula_2'     => 'Cláusula 2ª (editada na categoria)',
			'clausula_3'     => 'Cláusula 3ª (editada na categoria)',
			'parte1'         => 'Cláusulas 1ª–3ª juntas (retrocompatível)',
			'clausula_extra' => 'Cláusula extra da categoria (ex.: riscos)',
			'clausulaExtra'  => 'Alias de clausula_extra',
			'data_contrato'  => 'Cidade/UF e data por extenso',
		];
	}

	/** HTML padrão = view atual de matrícula/contrato (escola 1). */
	public static function modeloPadrao(): string {
		$path = __DIR__.'/../../../resources/view/admin/modules/matriculas/contrato.html';
		$html = @file_get_contents($path);
		if ($html === false || trim($html) === '') {
			return self::modeloPadraoFallback();
		}
		return $html;
	}

	/** Resolve o HTML a usar: custom da escola ou padrão. */
	public static function resolverModelo(?ClientesAssinantes $escola): string {
		if (
			$escola instanceof ClientesAssinantes
			&& ClientesAssinantes::temColunaModeloContrato()
			&& is_string($escola->modelo_contrato_html ?? null)
			&& trim((string)$escola->modelo_contrato_html) !== ''
		) {
			return (string)$escola->modelo_contrato_html;
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

	public static function render(array $vars, ?ClientesAssinantes $escola = null): string {
		return self::aplicar(self::resolverModelo($escola), $vars);
	}

	private static function modeloPadraoFallback(): string {
		return <<<'HTML'
<div class="bto">
  <button class="btn-impress" onclick="window.print()">Imprimir</button>
</div>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Contrato de Prestação de Serviços</title>
  <link rel="stylesheet" href="{{URL}}/resources/css/contrato.css">
</head>
<body>
  <div id="content">
    {{contratada}}
    {{contratante}}
    {{curso}}
    <hr><br>
    <p>Por este instrumento particular de prestação de serviços, fica estabelecido entre o aluno ou seu responsável acima doravante denominada simplesmente <b>CONTRATANTE/ALUNO</b> e escola como prestadora de serviços denominada como <b> CONTRATADA</b> estabelece o presente contrato com os termos abaixo descritos:</p>
    {{clausula_1}}
    {{clausula_2}}
    {{clausula_3}}
    <p><b>4ª REPOSIÇÃO DE AULAS</b> - O <b>CONTRATANTE/ALUNO</b> terá direito a reposição de aulas gratuitas somente com a apresentação de atestado médico ou outro documento que ateste a necessidade da falta.</p>
    <p><b>14ª FORO</b> - Fica eleito o Foro da Cidade, para dirimir quaisquer dúvidas, renunciando a qualquer outro por mais especial que seja.</p>
    {{clausula_extra}}
    <p>E por estarem de pleno acordo, firmam o presente contrato em duas vias de igual forma e teor, destinando-se uma via para cada uma das partes.</p><br>
    {{data_contrato}}
  </div>
</body>
</html>
HTML;
	}
}
