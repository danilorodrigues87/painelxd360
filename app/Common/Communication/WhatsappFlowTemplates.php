<?php

namespace App\Common\Communication;

/**
 * Templates prontos de fluxos WhatsApp (Fase B).
 * Cada template destaca um padrão diferente para facilitar o aprendizado.
 */
class WhatsappFlowTemplates {

	/** @return list<array{id:string,nome:string,descricao:string,definicao:array}> */
	public static function todos(): array {
		return [
			self::boasVindasSetores(),
			self::qualificacaoLead(),
			self::primeiraMensagem(),
			self::faqHorarios(),
			self::precosKeywordInicia(),
			self::matriculaKeywordExato(),
			self::expedienteCondicao(),
			self::confirmacaoSimNao(),
			self::delayApresentacao(),
			self::setVarTurmaLead(),
			self::pesquisaNps(),
			self::cancelamentoFinanceiro(),
			self::midiaCatalogo(),
			self::demoCompleto(),
		];
	}

	public static function getById(string $id): ?array {
		foreach (self::todos() as $t) {
			if ($t['id'] === $id) {
				return $t;
			}
		}
		return null;
	}

	private static function settings(int $horas = 24, string $acao = 'humano'): array {
		return [
			'timeout_horas' => $horas,
			'timeout_acao'  => $acao,
		];
	}

	private static function boasVindasSetores(): array {
		return [
			'id' => 'boas_vindas_setores',
			'nome' => 'Boas-vindas + escolha de setor',
			'descricao' => 'Gatilho: saudação (oi/olá). Mostra menu numérico e encaminha para setor ou fila humana.',
			'definicao' => [
				'trigger' => ['tipo' => 'saudacao', 'modo' => 'contem', 'palavra' => ''],
				'settings' => self::settings(24, 'humano'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'send_text',
						'config' => [
							'texto' => "Olá! Bem-vindo(a) ao atendimento automático.\nComo podemos ajudar?",
							'next' => 'n2',
						],
						'next' => 'n2',
					],
					'n2' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "Escolha digitando o *número*:\n*1* - Comercial\n*2* - Financeiro\n*3* - Falar com atendente",
							'opcoes' => [
								['num' => '1', 'label' => 'Comercial', 'next' => 'n3'],
								['num' => '2', 'label' => 'Financeiro', 'next' => 'n4'],
								['num' => '3', 'label' => 'Atendente', 'next' => 'n5'],
							],
						],
					],
					'n3' => [
						'type' => 'goto_setor',
						'config' => [
							'setor_id' => 0,
							'texto' => 'Certo! Vou te encaminhar para o *Comercial*. Aguarde um momento.',
						],
					],
					'n4' => [
						'type' => 'goto_setor',
						'config' => [
							'setor_id' => 0,
							'texto' => 'Certo! Vou te encaminhar para o *Financeiro*. Aguarde um momento.',
						],
					],
					'n5' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Perfeito. Em breve um atendente vai te responder.',
						],
					],
				],
			],
		];
	}

	private static function qualificacaoLead(): array {
		return [
			'id' => 'qualificacao_lead',
			'nome' => 'Qualificação de lead (CRM)',
			'descricao' => 'Gatilho: palavra “quero”. Perguntas livres (nome/curso) → criar_lead → fila humana. Usa {{variáveis}}.',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'contem', 'palavra' => 'quero'],
				'settings' => self::settings(12, 'encerrar'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'send_text',
						'config' => [
							'texto' => 'Ótimo! Vou te ajudar a iniciar. Responda algumas perguntas rápidas.',
							'next' => 'n2',
						],
						'next' => 'n2',
					],
					'n2' => [
						'type' => 'ask_text',
						'config' => [
							'texto' => 'Qual o seu *nome*?',
							'var' => 'nome',
							'next' => 'n3',
						],
						'next' => 'n3',
					],
					'n3' => [
						'type' => 'ask_text',
						'config' => [
							'texto' => 'Qual *curso* você tem interesse, {{nome}}?',
							'var' => 'curso',
							'next' => 'n4',
						],
						'next' => 'n4',
					],
					'n4' => [
						'type' => 'criar_lead',
						'config' => [
							'nome_var' => 'nome',
							'curso_var' => 'curso',
							'origem' => 'WhatsApp bot',
							'next' => 'n5',
						],
						'next' => 'n5',
					],
					'n5' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Obrigado, {{nome}}! Registramos seu interesse em *{{curso}}*. Em breve um atendente fala com você.',
						],
					],
				],
			],
		];
	}

	private static function primeiraMensagem(): array {
		return [
			'id' => 'primeira_mensagem',
			'nome' => 'Primeira mensagem (só boas-vindas)',
			'descricao' => 'Gatilho: primeira mensagem do contato. Fluxo curto: texto → opções → encerrar ou humano. Ideal para “porta de entrada”.',
			'definicao' => [
				'trigger' => ['tipo' => 'primeira_msg', 'modo' => 'contem', 'palavra' => ''],
				'settings' => self::settings(48, 'encerrar'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'send_text',
						'config' => [
							'texto' => "Olá! Esta é a primeira vez que falamos por aqui.\nSou o assistente automático da escola.",
							'next' => 'n2',
						],
						'next' => 'n2',
					],
					'n2' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "O que deseja?\n*1* - Ver opções de cursos\n*2* - Falar com a secretaria\n*3* - Só estou explorando",
							'opcoes' => [
								['num' => '1', 'label' => 'Cursos', 'next' => 'n3'],
								['num' => '2', 'label' => 'Secretaria', 'next' => 'n4'],
								['num' => '3', 'label' => 'Explorar', 'next' => 'n5'],
							],
						],
					],
					'n3' => [
						'type' => 'end',
						'config' => [
							'texto' => "Temos turmas de manhã, tarde e noite.\nDigite *quero* a qualquer momento para se cadastrar, ou *menu* para ver os setores.",
						],
					],
					'n4' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Combinado! Em breve a secretaria responde.',
						],
					],
					'n5' => [
						'type' => 'end',
						'config' => [
							'texto' => 'Sem problemas! Quando quiser, digite *menu* ou *oi*.',
						],
					],
				],
			],
		];
	}

	private static function faqHorarios(): array {
		return [
			'id' => 'faq_horarios',
			'nome' => 'FAQ — horários e endereço',
			'descricao' => 'Gatilho: contém “horario”. Menu de FAQ com respostas prontas e encerrar (sem CRM).',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'contem', 'palavra' => 'horario'],
				'settings' => self::settings(6, 'encerrar'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "Sobre o que você quer saber?\n*1* - Horário de funcionamento\n*2* - Endereço\n*3* - Falar com alguém",
							'opcoes' => [
								['num' => '1', 'label' => 'Horário', 'next' => 'n2'],
								['num' => '2', 'label' => 'Endereço', 'next' => 'n3'],
								['num' => '3', 'label' => 'Humano', 'next' => 'n4'],
							],
						],
					],
					'n2' => [
						'type' => 'end',
						'config' => [
							'texto' => "Funcionamos de *segunda a sexta*, das 8h às 18h.\nSábados: 8h às 12h.\n(Edite este texto com o horário real da escola.)",
						],
					],
					'n3' => [
						'type' => 'end',
						'config' => [
							'texto' => "Estamos em: *Rua Exemplo, 123 — Centro*.\n(Edite com o endereço real.)",
						],
					],
					'n4' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Ok! Vou te colocar na fila de atendimento.',
						],
					],
				],
			],
		];
	}

	private static function precosKeywordInicia(): array {
		return [
			'id' => 'precos_keyword_inicia',
			'nome' => 'Preços — palavra que começa com…',
			'descricao' => 'Gatilho: modo “começa com” a palavra “valor”. Ensina diferença entre contém / inicia / exato.',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'inicia', 'palavra' => 'valor'],
				'settings' => self::settings(24, 'humano'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'send_text',
						'config' => [
							'texto' => 'Os valores dependem do curso e da turma. Vou te ajudar a escolher.',
							'next' => 'n2',
						],
						'next' => 'n2',
					],
					'n2' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "Qual área?\n*1* - Informática\n*2* - Idiomas\n*3* - Quero falar com o comercial",
							'opcoes' => [
								['num' => '1', 'label' => 'Informática', 'next' => 'n3'],
								['num' => '2', 'label' => 'Idiomas', 'next' => 'n4'],
								['num' => '3', 'label' => 'Comercial', 'next' => 'n5'],
							],
						],
					],
					'n3' => [
						'type' => 'end',
						'config' => [
							'texto' => "Informática: mensalidades a partir de *R$ XX*.\nPara proposta personalizada, digite *quero*.",
						],
					],
					'n4' => [
						'type' => 'end',
						'config' => [
							'texto' => "Idiomas: mensalidades a partir de *R$ YY*.\nPara proposta personalizada, digite *quero*.",
						],
					],
					'n5' => [
						'type' => 'goto_setor',
						'config' => [
							'setor_id' => 0,
							'texto' => 'Encaminhando para o comercial…',
						],
					],
				],
			],
		];
	}

	private static function matriculaKeywordExato(): array {
		return [
			'id' => 'matricula_keyword_exato',
			'nome' => 'Matrícula — palavra exata',
			'descricao' => 'Gatilho: modo “exato” na palavra “matricula”. Só dispara se a mensagem for exatamente essa (não “quero matrícula”).',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'exato', 'palavra' => 'matricula'],
				'settings' => self::settings(12, 'humano'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'send_text',
						'config' => [
							'texto' => 'Perfeito! Vamos iniciar sua matrícula.',
							'next' => 'n2',
						],
						'next' => 'n2',
					],
					'n2' => [
						'type' => 'ask_text',
						'config' => [
							'texto' => 'Qual o *nome completo* do aluno?',
							'var' => 'nome',
							'next' => 'n3',
						],
						'next' => 'n3',
					],
					'n3' => [
						'type' => 'ask_text',
						'config' => [
							'texto' => '{{nome}}, qual curso deseja matricular?',
							'var' => 'curso',
							'next' => 'n4',
						],
						'next' => 'n4',
					],
					'n4' => [
						'type' => 'criar_lead',
						'config' => [
							'nome_var' => 'nome',
							'curso_var' => 'curso',
							'origem' => 'WhatsApp matrícula',
							'next' => 'n5',
						],
						'next' => 'n5',
					],
					'n5' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Recebemos os dados de *{{nome}}* para *{{curso}}*. A secretaria completa a matrícula com você.',
						],
					],
				],
			],
		];
	}

	private static function expedienteCondicao(): array {
		return [
			'id' => 'expediente_condicao',
			'nome' => 'Fora do expediente → fila',
			'descricao' => 'Gatilho: saudação. Nó condição com operador “fora do expediente”: se fora, humano; se dentro, menu normal.',
			'definicao' => [
				'trigger' => ['tipo' => 'saudacao', 'modo' => 'contem', 'palavra' => ''],
				'settings' => self::settings(8, 'humano'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'condition',
						'config' => [
							'campo' => 'ultima_resposta',
							'op' => 'fora_expediente',
							'valor' => '',
							'next_true' => 'n2',
							'next_false' => 'n3',
						],
					],
					'n2' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'No momento estamos *fora do horário* de atendimento. Deixe sua mensagem — respondemos assim que possível.',
						],
					],
					'n3' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "Estamos online! Como posso ajudar?\n*1* - Comercial\n*2* - Financeiro\n*3* - Atendente",
							'opcoes' => [
								['num' => '1', 'label' => 'Comercial', 'next' => 'n4'],
								['num' => '2', 'label' => 'Financeiro', 'next' => 'n5'],
								['num' => '3', 'label' => 'Atendente', 'next' => 'n6'],
							],
						],
					],
					'n4' => [
						'type' => 'goto_setor',
						'config' => [
							'setor_id' => 0,
							'texto' => 'Indo para o Comercial…',
						],
					],
					'n5' => [
						'type' => 'goto_setor',
						'config' => [
							'setor_id' => 0,
							'texto' => 'Indo para o Financeiro…',
						],
					],
					'n6' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Um atendente vai te responder em breve.',
						],
					],
				],
			],
		];
	}

	private static function confirmacaoSimNao(): array {
		return [
			'id' => 'confirmacao_sim_nao',
			'nome' => 'Confirmação sim/não (condição)',
			'descricao' => 'Pergunta livre → condição “contém” a palavra *sim* na última resposta. Mostra ramificação verdadeiro/falso.',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'contem', 'palavra' => 'visita'],
				'settings' => self::settings(24, 'encerrar'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'send_text',
						'config' => [
							'texto' => 'Que bom! Podemos agendar uma visita à escola.',
							'next' => 'n2',
						],
						'next' => 'n2',
					],
					'n2' => [
						'type' => 'ask_text',
						'config' => [
							'texto' => 'Você confirma o interesse em visitar? Responda *sim* ou *não*.',
							'var' => 'confirma',
							'next' => 'n3',
						],
						'next' => 'n3',
					],
					'n3' => [
						'type' => 'condition',
						'config' => [
							'campo' => 'ultima_resposta',
							'op' => 'contem',
							'valor' => 'sim',
							'next_true' => 'n4',
							'next_false' => 'n5',
						],
					],
					'n4' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Ótimo! Anotamos sua confirmação. Um atendente combina o melhor horário.',
						],
					],
					'n5' => [
						'type' => 'end',
						'config' => [
							'texto' => 'Tudo bem! Quando quiser, digite *visita* de novo ou *menu*.',
						],
					],
				],
			],
		];
	}

	private static function delayApresentacao(): array {
		return [
			'id' => 'delay_apresentacao',
			'nome' => 'Apresentação com delay',
			'descricao' => 'Mostra o nó *delay* (pausa de até 3s entre mensagens). Útil para não “despejar” texto de uma vez.',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'contem', 'palavra' => 'sobre'],
				'settings' => self::settings(24, 'encerrar'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'send_text',
						'config' => [
							'texto' => 'Somos uma escola de cursos profissionalizantes.',
							'next' => 'n2',
						],
						'next' => 'n2',
					],
					'n2' => [
						'type' => 'delay',
						'config' => [
							'segundos' => 2,
							'next' => 'n3',
						],
						'next' => 'n3',
					],
					'n3' => [
						'type' => 'send_text',
						'config' => [
							'texto' => 'Temos turmas presenciais e opções online.',
							'next' => 'n4',
						],
						'next' => 'n4',
					],
					'n4' => [
						'type' => 'delay',
						'config' => [
							'segundos' => 1,
							'next' => 'n5',
						],
						'next' => 'n5',
					],
					'n5' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "Quer continuar?\n*1* - Quero me cadastrar\n*2* - Falar com atendente\n*3* - Encerrar",
							'opcoes' => [
								['num' => '1', 'label' => 'Cadastrar', 'next' => 'n6'],
								['num' => '2', 'label' => 'Humano', 'next' => 'n7'],
								['num' => '3', 'label' => 'Fim', 'next' => 'n8'],
							],
						],
					],
					'n6' => [
						'type' => 'end',
						'config' => [
							'texto' => 'Digite *quero* para iniciar o cadastro de interesse.',
						],
					],
					'n7' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Transferindo para um atendente…',
						],
					],
					'n8' => [
						'type' => 'end',
						'config' => [
							'texto' => 'Obrigado pelo contato!',
						],
					],
				],
			],
		];
	}

	private static function setVarTurmaLead(): array {
		return [
			'id' => 'set_var_turma_lead',
			'nome' => 'Opções + set_var + lead',
			'descricao' => 'Menu define a variável *curso* com o nó set_var (sem digitar texto livre), depois pergunta o nome e cria o lead.',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'contem', 'palavra' => 'turma'],
				'settings' => self::settings(12, 'humano'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "Qual turma te interessa?\n*1* - Manhã\n*2* - Tarde\n*3* - Noite",
							'opcoes' => [
								['num' => '1', 'label' => 'Manhã', 'next' => 'n2'],
								['num' => '2', 'label' => 'Tarde', 'next' => 'n3'],
								['num' => '3', 'label' => 'Noite', 'next' => 'n4'],
							],
						],
					],
					'n2' => [
						'type' => 'set_var',
						'config' => [
							'var' => 'curso',
							'valor' => 'Turma manhã',
							'next' => 'n5',
						],
						'next' => 'n5',
					],
					'n3' => [
						'type' => 'set_var',
						'config' => [
							'var' => 'curso',
							'valor' => 'Turma tarde',
							'next' => 'n5',
						],
						'next' => 'n5',
					],
					'n4' => [
						'type' => 'set_var',
						'config' => [
							'var' => 'curso',
							'valor' => 'Turma noite',
							'next' => 'n5',
						],
						'next' => 'n5',
					],
					'n5' => [
						'type' => 'ask_text',
						'config' => [
							'texto' => 'Qual o seu *nome*? (interesse: {{curso}})',
							'var' => 'nome',
							'next' => 'n6',
						],
						'next' => 'n6',
					],
					'n6' => [
						'type' => 'criar_lead',
						'config' => [
							'nome_var' => 'nome',
							'curso_var' => 'curso',
							'origem' => 'WhatsApp turma',
							'next' => 'n7',
						],
						'next' => 'n7',
					],
					'n7' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Obrigado, {{nome}}! Registramos *{{curso}}*. Em breve falamos com você.',
						],
					],
				],
			],
		];
	}

	private static function pesquisaNps(): array {
		return [
			'id' => 'pesquisa_nps',
			'nome' => 'Pesquisa rápida (NPS 1–5)',
			'descricao' => 'Gatilho: “pesquisa”. Coleta nota com opções, grava com set_var e encerra. Bom para feedback sem CRM.',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'contem', 'palavra' => 'pesquisa'],
				'settings' => self::settings(6, 'encerrar'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'send_text',
						'config' => [
							'texto' => 'Sua opinião é importante! Leva menos de 30 segundos.',
							'next' => 'n2',
						],
						'next' => 'n2',
					],
					'n2' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "De 1 a 5, quanto você recomendaria nossa escola?\n*1* - 1\n*2* - 2\n*3* - 3\n*4* - 4\n*5* - 5",
							'opcoes' => [
								['num' => '1', 'label' => '1', 'next' => 'n3'],
								['num' => '2', 'label' => '2', 'next' => 'n4'],
								['num' => '3', 'label' => '3', 'next' => 'n5'],
								['num' => '4', 'label' => '4', 'next' => 'n6'],
								['num' => '5', 'label' => '5', 'next' => 'n7'],
							],
						],
					],
					'n3' => [
						'type' => 'set_var',
						'config' => ['var' => 'nota', 'valor' => '1', 'next' => 'n8'],
						'next' => 'n8',
					],
					'n4' => [
						'type' => 'set_var',
						'config' => ['var' => 'nota', 'valor' => '2', 'next' => 'n8'],
						'next' => 'n8',
					],
					'n5' => [
						'type' => 'set_var',
						'config' => ['var' => 'nota', 'valor' => '3', 'next' => 'n8'],
						'next' => 'n8',
					],
					'n6' => [
						'type' => 'set_var',
						'config' => ['var' => 'nota', 'valor' => '4', 'next' => 'n8'],
						'next' => 'n8',
					],
					'n7' => [
						'type' => 'set_var',
						'config' => ['var' => 'nota', 'valor' => '5', 'next' => 'n8'],
						'next' => 'n8',
					],
					'n8' => [
						'type' => 'ask_text',
						'config' => [
							'texto' => 'Obrigado pela nota *{{nota}}*! Quer deixar um comentário? (ou digite *não*)',
							'var' => 'comentario',
							'next' => 'n9',
						],
						'next' => 'n9',
					],
					'n9' => [
						'type' => 'end',
						'config' => [
							'texto' => 'Recebemos: nota {{nota}} — “{{comentario}}”. Muito obrigado!',
						],
					],
				],
			],
		];
	}

	private static function cancelamentoFinanceiro(): array {
		return [
			'id' => 'cancelamento_financeiro',
			'nome' => 'Cancelamento / boleto → Financeiro',
			'descricao' => 'Gatilho: contém “boleto”. Triagem rápida e encaminha ao setor financeiro (ajuste o setor_id depois).',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'contem', 'palavra' => 'boleto'],
				'settings' => self::settings(24, 'humano'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "Sobre financeiro, o que precisa?\n*1* - 2ª via de boleto\n*2* - Negociação / atraso\n*3* - Cancelamento\n*4* - Outro assunto",
							'opcoes' => [
								['num' => '1', 'label' => 'Boleto', 'next' => 'n2'],
								['num' => '2', 'label' => 'Negociação', 'next' => 'n2'],
								['num' => '3', 'label' => 'Cancelamento', 'next' => 'n3'],
								['num' => '4', 'label' => 'Outro', 'next' => 'n4'],
							],
						],
					],
					'n2' => [
						'type' => 'goto_setor',
						'config' => [
							'setor_id' => 0,
							'texto' => 'Vou te encaminhar ao *Financeiro* para cuidar disso.',
						],
					],
					'n3' => [
						'type' => 'send_text',
						'config' => [
							'texto' => 'Entendi. Cancelamentos passam por análise do financeiro.',
							'next' => 'n2b',
						],
						'next' => 'n2b',
					],
					'n2b' => [
						'type' => 'goto_setor',
						'config' => [
							'setor_id' => 0,
							'texto' => 'Encaminhando ao Financeiro…',
						],
					],
					'n4' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Ok! Um atendente vai te ajudar com outro assunto.',
						],
					],
				],
			],
		];
	}

	private static function midiaCatalogo(): array {
		return [
			'id' => 'midia_catalogo',
			'nome' => 'Catálogo com mídia (upload)',
			'descricao' => 'Mostra o nó *enviar mídia*. Após aplicar, edite o passo de mídia e faça upload da imagem/PDF do catálogo.',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'contem', 'palavra' => 'catalogo'],
				'settings' => self::settings(24, 'encerrar'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'send_text',
						'config' => [
							'texto' => 'Segue nosso material! (Se a mídia não aparecer, edite o fluxo e faça o *upload* no passo de mídia.)',
							'next' => 'n2',
						],
						'next' => 'n2',
					],
					'n2' => [
						'type' => 'send_media',
						'config' => [
							'tipo' => 'image',
							'path' => '',
							'caption' => 'Catálogo de cursos',
							'mimetype' => '',
							'nome' => '',
							'next' => 'n3',
						],
						'next' => 'n3',
					],
					'n3' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "Gostou?\n*1* - Quero me inscrever\n*2* - Falar com comercial\n*3* - Encerrar",
							'opcoes' => [
								['num' => '1', 'label' => 'Inscrever', 'next' => 'n4'],
								['num' => '2', 'label' => 'Comercial', 'next' => 'n5'],
								['num' => '3', 'label' => 'Fim', 'next' => 'n6'],
							],
						],
					],
					'n4' => [
						'type' => 'end',
						'config' => [
							'texto' => 'Digite *quero* para cadastrar seu interesse.',
						],
					],
					'n5' => [
						'type' => 'goto_setor',
						'config' => [
							'setor_id' => 0,
							'texto' => 'Indo para o Comercial…',
						],
					],
					'n6' => [
						'type' => 'end',
						'config' => [
							'texto' => 'Obrigado! Qualquer dúvida, estamos por aqui.',
						],
					],
				],
			],
		];
	}

	private static function demoCompleto(): array {
		return [
			'id' => 'demo_completo',
			'nome' => 'Demo completa (aprender tudo)',
			'descricao' => 'Roteiro didático: texto → delay → pergunta → condição → set_var → lead → humano/fim. Gatilho: digite “demo”. Use o *simulador* para treinar.',
			'definicao' => [
				'trigger' => ['tipo' => 'keyword', 'modo' => 'exato', 'palavra' => 'demo'],
				'settings' => self::settings(4, 'encerrar'),
				'start' => 'n1',
				'nodes' => [
					'n1' => [
						'type' => 'send_text',
						'config' => [
							'texto' => "*Demo do bot*\nVou te mostrar vários tipos de passo. Responda com calma.",
							'next' => 'n2',
						],
						'next' => 'n2',
					],
					'n2' => [
						'type' => 'delay',
						'config' => ['segundos' => 1, 'next' => 'n3'],
						'next' => 'n3',
					],
					'n3' => [
						'type' => 'ask_text',
						'config' => [
							'texto' => 'Primeiro: qual o seu *primeiro nome*?',
							'var' => 'nome',
							'next' => 'n4',
						],
						'next' => 'n4',
					],
					'n4' => [
						'type' => 'ask_options',
						'config' => [
							'texto' => "{{nome}}, escolha um caminho:\n*1* - Criar lead de teste no CRM\n*2* - Só encerrar a demo\n*3* - Ir para fila humana",
							'opcoes' => [
								['num' => '1', 'label' => 'Lead', 'next' => 'n5'],
								['num' => '2', 'label' => 'Encerrar', 'next' => 'n9'],
								['num' => '3', 'label' => 'Humano', 'next' => 'n10'],
							],
						],
					],
					'n5' => [
						'type' => 'set_var',
						'config' => [
							'var' => 'curso',
							'valor' => 'Demo bot WhatsApp',
							'next' => 'n6',
						],
						'next' => 'n6',
					],
					'n6' => [
						'type' => 'ask_text',
						'config' => [
							'texto' => 'Confirma gravar o lead? Digite *sim* para continuar.',
							'var' => 'confirma',
							'next' => 'n7',
						],
						'next' => 'n7',
					],
					'n7' => [
						'type' => 'condition',
						'config' => [
							'campo' => 'ultima_resposta',
							'op' => 'contem',
							'valor' => 'sim',
							'next_true' => 'n8',
							'next_false' => 'n9',
						],
					],
					'n8' => [
						'type' => 'criar_lead',
						'config' => [
							'nome_var' => 'nome',
							'curso_var' => 'curso',
							'origem' => 'WhatsApp demo',
							'next' => 'n11',
						],
						'next' => 'n11',
					],
					'n9' => [
						'type' => 'end',
						'config' => [
							'texto' => 'Demo encerrada, {{nome}}. Digite *demo* de novo quando quiser repetir.',
						],
					],
					'n10' => [
						'type' => 'goto_humano',
						'config' => [
							'texto' => 'Demo → fila humana. {{nome}}, um atendente assume daqui.',
						],
					],
					'n11' => [
						'type' => 'end',
						'config' => [
							'texto' => 'Lead de teste criado para *{{nome}}* / {{curso}}. (No simulador isso não grava de verdade.)',
						],
					],
				],
			],
		];
	}
}
