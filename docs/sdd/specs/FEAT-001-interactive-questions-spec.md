# FEAT-001: Perguntas interactivas

Data: 2026-10-23. Estado: Concluído.

O assistente pede esclarecimentos com um formulário na conversa, como Codex e Claude.
Utilizadores: os utilizadores autorizados do ChatAI, na página inteira e no chat flutuante.

## Requisitos e aceitação
- RF-01: `show_ui` aceita `questions`, com 1 a 3 perguntas, cada uma com até 6 opções (rótulo e descrição) e resposta livre. Sem selecção automática.
- RF-02: mostrar uma pergunta por passo com indicador, Anterior/Seguinte, manter respostas ao voltar e só enviar no último passo. Enviar todas as respostas numa mensagem e continuar a mesma conversa. Nenhuma pergunta pode ficar vazia.
- RF-03: validar no servidor a conversa, mensagem, componente, opções e comprimentos. Nunca confiar nos rótulos enviados pelo browser.
- RF-04: guardar o resumo respondido no componente original, resistir a reload e recusar segundo envio.
- RF-05: ao mostrar perguntas, terminar o turno e aguardar a resposta, sem executar mudanças adicionais.
- RF-06: o formulário pendente fica fixo imediatamente acima da caixa de mensagem, fora da rolagem do histórico. Após enviar, passa a resumo no histórico e o bloco fixo desaparece.
- RF-07: cartão compacto semelhante à referência do Codex, com cabeçalho Pergunta, indicador de passo, opções numeradas sem cartões individuais e resposta livre em linha com os botões.
- RNF: texto escapado, teclado e labels nativos, layout móvel e modo escuro. Reutilizar catálogo, SSE, autorização e histórico existentes; sem migrations.

Sucesso: seleccionar uma opção, preencher outra resposta livre, enviar e ver a continuação e o resumo persistido.
Erros: vazio, opção inexistente, outro dono/conversa, reenvio e indisponibilidade do provedor.
