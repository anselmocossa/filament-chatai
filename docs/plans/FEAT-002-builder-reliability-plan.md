# FEAT-002: Plano técnico

Stack real: PHP ^8.2, Laravel/Illuminate 11/12/13, Filament ^5, Pest ^2/3/4. Dependência: opis/json-schema ^2.4 (Apache-2.0), API oficial https://opis.io/json-schema/2.x/php-validator.html.

1. Support/ToolArguments.php adapta Validator e ErrorFormatter. Converter schemas PHP em objectos JSON mantendo listas e objectos vazios; validar dados sem alterar os originais. Não registar resolvedores de rede. ChatAiTool::call valida antes do handler.
2. BuilderTools recebe snapshot das ferramentas autorizadas do ToolRegistry + show_ui. describe_tools devolve schemas exactos. create_skill valida nomes e exemplos JSON antes de guardar, sem executar exemplos. Exemplos canónicos guardados nas instruções existentes.
3. SkillTool recebe snapshot e inclui contratos actuais ao carregar. Orchestrator::builderHint e descrições explicam a escolha e evitam pedir valores de execução durante a criação. Nenhum parser de intenções por palavras-chave.
4. Testar sem executar operações de escrita reais; E2E com skill de teste e conta local temporária. Instalar a dependência no portal local, aplicar só ficheiros alterados e limpar fixtures no fim.

Limitação: validação estrutural não garante que toda a prosa da skill esteja certa. O contrato anexado e a validação em runtime protegem os parâmetros reais.
