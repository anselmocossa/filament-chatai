# FEAT-001: Tarefas

Spec: ../specs/FEAT-001-interactive-questions-spec.md

- [x] T-01: Estender Catalog, ComponentValidator e SystemPrompt. Testar normalização, limites, dados inválidos e pausa do Orchestrator.
- [x] T-02: Estender MessageStreamController com validação/persistência de respostas. Testar continuação, histórico, vazio, opções, isolamento e duplicados.
- [x] T-03: Estender catálogo Blade, Alpine e CSS existentes, traduções EN/PT. Validar submissão, falha de rede, teclado e responsive; suite completa e fluxo real no browser.

Reutilização: Conversation/Message, autorização do endpoint, show_ui, SSE, catálogo Blade e classes de formulário/botão. Criar apenas helper de validação de respostas e testes específicos.

Validação real: modelo apresentou perguntas na página e no painel flutuante; botão desactivado com perguntas vazias, selecção e resposta livre enviadas, continuação recebida e resumo conservado após reload.

Resultado final: 242 testes Pest (926 assertions) e 4 testes Node aprovados; Pint e sintaxe JS aprovados. E2E com o modelo real no portal local: página inteira + painel flutuante, opções e resposta livre, estado vazio bloqueado, resposta do assistente e reload. Fixture local removida.

Ajuste pedido: uma pergunta por passo, indicador, Anterior/Seguinte, respostas conservadas e envio no último passo. Testes Node cobrem validação do passo e navegação sem avançar vazio.

Validação do ajuste: 242 testes Pest e 6 testes Node aprovados. E2E real com três passos: primeiro passo vazio bloqueado, avançar, preencher texto, voltar e avançar conservando ambos os valores, botão final, submissão única e resposta real do assistente. Fixture removida.

Ajuste de posição confirmado pelo utilizador: formulário pendente fixo e unido ao composer, fora do histórico; resumo respondido conserva-se na mensagem original. Renderer extraído para components/questions.blade.php para reutilização sem duplicar formulários. Testes: 242 Pest + 8 Node. E2E: histórico com 20 mensagens rolou de scrollTop 3809 para 0 mantendo dockTop 734.30 e dockBottom/composerTop 1131.03; uma pergunta visível, navegação, valores conservados e submissão pelo mesmo endpoint.

Ajuste visual: opção «Escrever outra resposta» e textarea no mesmo contentor, sem rótulo separado. Focar o editor selecciona a opção livre. Ao seleccionar uma opção predefinida, o rascunho livre conserva-se localmente mas não entra no envio. Validado no browser (contentor único, selecção automática, ausência do rótulo duplicado e envio correcto), 242 Pest + 9 Node aprovados.

Referência final enviada pelo utilizador (Codex): renderer compacto, opções numeradas planas e input livre de uma linha no rodapé. Dock mantém a largura do composer, com cerca de 7px entre cartões; altura medida 204px para duas opções. URLs CSS/JS têm hash de conteúdo para evitar cache de versões anteriores. E2E: selecção, voltar/avançar, input em linha e envio; 242 Pest + 9 Node aprovados.
