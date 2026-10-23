# FEAT-001: Plano técnico

Estado: Executado.

Laravel (Illuminate 11/12/13), Filament ^5, Alpine fornecido pelo Filament, Pest ^2/3/4. Sem dependências novas.

1. Modificar src/Ui/{Catalog,ComponentValidator}.php e src/Agent/{SystemPrompt,Orchestrator}.php: tipo questions, limites, pausa imediata.
2. Criar src/Ui/QuestionAnswer.php para formatar/persistir respostas dentro de transacção com lock; MessageStreamController e PlaygroundController invocam após rate limit/budget e antes de abrir SSE. Usar IDs de mensagem e índice do componente, sempre dentro da conversa autorizada.
3. Modificar resources/views/components/catalog.blade.php, resources/dist/chatai.{js,css}, resources/lang/{en,pt_PT}/chatai.php. Continuar no endpoint actual; estado respondido guardado no JSON components existente.
4. Tests: Pest (SQLite Testbench isolado), Node para estado Alpine, E2E browser no portal local. Replicar apenas ficheiros alterados no pacote instalado e publicar assets locais. Sem commits ou release.

Referência de API verificada: https://alpinejs.dev/directives/model (radio, textarea e binding).
Riscos: requests repetidos (lock + status), dados forjados (texto canónico no servidor), erro SSE (respostas persistidas e retry normal da mensagem), provider pequeno não chamar show_ui (instrução explícita).

Descoberta E2E: Alpine requer um booleano explícito no disabled do fieldset; usar `busy || !!component.busy` evita manter o formulário desactivado quando a propriedade ainda não existe.

Posição final: components/conversation.blade.php apresenta as perguntas pendentes fora do scroller, imediatamente acima do composer. components/questions.blade.php reutiliza o renderer existente para o formulário fixo e o resumo no histórico. pendingQuestionMessages() escolhe o conjunto mais recente sem reactivar perguntas antigas após enviar.
