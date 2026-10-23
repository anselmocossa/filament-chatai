---
data: 2026-10-23
tipo: especificacao
estado: Sucesso
---

# FEAT-003: Plano técnico

Estado: Executado e validado localmente.

Reutilizar Orchestrator, ChatAiTool, FakeLlmClient, ToolAction e testes de confirmação. Novo HarnessTest agrupa cenários de interacção entre modelo, ferramentas e estado da conversa; não substitui componentes de produção.

Orchestrator verifica Stop antes de cada chamada e preserva o protocolo com respostas de cancelamento. Uma resposta final sem texto nem componentes recebe mensagem localizada. ChatAiTool conserva o diagnóstico nos logs e entrega erro genérico ao modelo. Traduções en e pt_PT. Sem migrations nem dependências novas.

Validar primeiro falhas reproduzidas, depois suite completa e UI real com conta temporária local. Aplicar somente ficheiros do pacote local e remover os dados de teste.
