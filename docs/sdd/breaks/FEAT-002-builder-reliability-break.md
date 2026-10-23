# FEAT-002: Tarefas

- [x] T-01: Validação de argumentos com biblioteca JSON Schema. Testar enum inválido sem handler, obrigatórios/tipos/nested/limites e chamadas válidas sem coerção; contratos permissivos conservam parâmetros extra.
- [x] T-02: Introspecção de ferramentas e validação de skills. Testar visibilidade, dependências desconhecidas, exemplos inválidos e exemplos válidos sem execução.
- [x] T-03: Orientação de criação vs execução e contratos no carregamento. Testar regressão com FakeLlm, contratos actuais e ferramentas indisponíveis sem exposição.
- [x] T-04: Suite, smoke e E2E real local, documentação e aplicação do pacote local.

Reutiliza: ChatAiTool, ToolRegistry, BuilderTools, SkillTool, Orchestrator, confirmação existente, models e testes Testbench. Cria somente adaptador de validação; sem novo tipo de ferramenta ou novo sistema de workflows.
