<?php

namespace App\Common\Helpers;

/**
 * Tokens e modelos sugeridos para cláusulas de contrato por categoria.
 */
class ContratoClausulaHelper {

	/** @return array<string,string> */
	public static function catalogoTokens(): array {
		return [
			'aulas_semanais'  => 'Quantidade de aulas por semana (matrícula)',
			'meses_duracao'   => 'Quantidade de parcelas/meses do contrato',
			'qtd_parcelas'    => 'Mesmo que meses_duracao',
			'valor_parcela'   => 'Valor da parcela formatado (R$)',
			'primeira_parcela'=> 'Data da 1ª parcela (dd/mm/aaaa)',
			'dia_vencimento'  => 'Dia do vencimento mensal',
			'multa_atraso_pct' => 'Multa por atraso (%) — gravado na matrícula',
			'juros_mora_pct_mes' => 'Juros de mora (% a.m.) — gravado na matrícula',
			'multa_cancelamento_pct' => 'Multa rescisória (%) — gravado na matrícula',
			'carencia_dias'   => 'Carência após vencimento (dias) — gravado na matrícula',
		];
	}

	/**
	 * @param array<string,mixed> $matricula
	 * @return array<string,string>
	 */
	public static function contextoTokens(array $matricula): array {
		$primeiraParcela = ($matricula['dia_vencimento'] ?? '').'/'
			.($matricula['primeiro_mes'] ?? '').'/'
			.($matricula['primeiro_ano'] ?? '');
		$qtd = (int)($matricula['qtd_parcelas'] ?? 0);
		$params = EncargosContratoHelper::parametrosFromMatriculaRow($matricula);

		return [
			'aulas_semanais'   => (string)(int)($matricula['aulas_semanais'] ?? 1),
			'meses_duracao'    => (string)$qtd,
			'qtd_parcelas'     => (string)$qtd,
			'valor_parcela'    => NumeroHelper::moedaBr($matricula['valor'] ?? 0),
			'primeira_parcela' => $primeiraParcela,
			'dia_vencimento'   => (string)(int)($matricula['dia_vencimento'] ?? 0),
			'multa_atraso_pct' => self::formatPctToken($params['multa_atraso_pct']),
			'juros_mora_pct_mes' => self::formatPctToken($params['juros_mora_pct_mes']),
			'multa_cancelamento_pct' => self::formatPctToken($params['multa_cancelamento_pct']),
			'carencia_dias'    => (string)(int)$params['carencia_dias'],
		];
	}

	private static function formatPctToken(float $pct): string {
		return number_format($pct, 2, ',', '.');
	}

	public static function aplicarTokens(string $html, array $tokens): string {
		if ($html === '') {
			return '';
		}
		$mapa = [];
		foreach ($tokens as $k => $v) {
			$mapa['{{'.$k.'}}'] = (string)$v;
			$mapa['{'.$k.'}'] = (string)$v;
		}
		return str_replace(array_keys($mapa), array_values($mapa), $html);
	}

	/**
	 * @param array<string,mixed>|null $categoriaRow
	 * @param array<string,mixed> $matricula
	 * @return array<string,string>
	 */
	public static function montarClausulas(?array $categoriaRow, array $matricula): array {
		$tokens = self::contextoTokens($matricula);
		$row = is_array($categoriaRow) ? $categoriaRow : [];

		$c1 = self::aplicarTokens((string)($row['contrato_clausula_1'] ?? ''), $tokens);
		$c2 = self::aplicarTokens((string)($row['contrato_clausula_2'] ?? ''), $tokens);
		$c3 = self::aplicarTokens((string)($row['contrato_clausula_3'] ?? ''), $tokens);
		$extra = self::aplicarTokens((string)($row['contrato_clausula_extra'] ?? ''), $tokens);

		return [
			'clausula_1'     => $c1,
			'clausula_2'     => $c2,
			'clausula_3'     => $c3,
			'clausula_extra' => $extra,
			'clausulaExtra'  => $extra,
			'parte1'         => $c1.$c2.$c3,
		];
	}

	/** @return array<string,mixed> */
	public static function modelosSugeridos(): array {
		return [
			'clausula_1' => '<p><b>1ª A CONTRATADA</b> prestará os serviços educacionais descritos neste contrato, com material didático e acompanhamento pedagógico conforme a metodologia da escola, podendo haver custos adicionais de materiais informados previamente ao <b>CONTRATANTE/ALUNO</b>.</p>',
			'clausula_2' => '<p><b>2ª AULAS</b> – As aulas serão ministradas na sede da <b>CONTRATADA</b> nos dias e horários definidos no quadro acima, com {{aulas_semanais}} aula(s) semanal(is). O <b>CONTRATANTE/ALUNO</b> compromete-se a frequentá-las respeitando o Regimento Interno Escolar.</p>',
			'clausula_3' => '<p><b>3ª PRAZO DE DURAÇÃO</b> – O período previsto do contrato é de {{meses_duracao}} meses, podendo ser prorrogado ou encerrado conforme regras deste instrumento e da escola.</p>',
			'clausula_extra' => '',
			'pagamento_parcelado' => '<span>Forma de pagamento <b>Parcelado em {{qtd_parcelas}} vezes</b></span><span> sendo a primeira parcela no dia <b>{{primeira_parcela}}</b><br><span>Parcelas fixas de <b>R$ {{valor_parcela}}</b></span> com vencimento mensal dia <b>{{dia_vencimento}}</b><br><span><b>Atraso:</b> após {{carencia_dias}} dia(s) de carência, multa de <b>{{multa_atraso_pct}}%</b> e juros de mora de <b>{{juros_mora_pct_mes}}%</b> ao mês (proporcional). <b>Cancelamento:</b> multa rescisória de <b>{{multa_cancelamento_pct}}%</b> sobre parcelas futuras.</span><br>',
			'pagamento_vista' => '<span>Forma de pagamento <b>à vista</b></span><span> pago no ato da matricula em <b>{{primeira_parcela}}</b><br><span>o valor de <b>R$ {{valor_parcela}}</b></span><br>',
			'pagamento_bolsista' => '<span>Forma de pagamento: <b>BOLSISTA</b> — isento de mensalidades (sem geração de carnê/débitos).</span><br><span>Duração prevista: <b>{{meses_duracao}} meses</b>.</span><br>',
			'obs_pontualidade' => '<span><b>Obs:</b> Bônus de 10% no valor das parcelas <b>PAGAS ANTECIPADAMENTE</b> ao dia do vencimento acima firmado.</span>',
		];
	}

	public static function textoObsPontualidadePadrao(): string {
		return self::modelosSugeridos()['obs_pontualidade'];
	}
}
