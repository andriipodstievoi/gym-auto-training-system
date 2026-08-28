"""Deterministic programme generation.

The rule engine decides every number in a plan: split, weekly set volume,
intensity targets, progression model and exercise selection. The LLM layer
(``app.llm``) only ever writes prose on top of what this module produced.

Implemented in milestone M6.
"""

ENGINE_VERSION = "0.1.0-stub"
