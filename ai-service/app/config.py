"""Runtime settings, read from the environment."""

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="AI_", env_file=".env", extra="ignore")

    #: Bind address for uvicorn in local development.
    host: str = "127.0.0.1"
    port: int = 8001

    #: Anthropic key for the optional prose layer. Empty means the service
    #: still generates full plans - it just skips the coaching narrative.
    anthropic_api_key: str = ""
    llm_model: str = "claude-opus-5"

    @property
    def llm_enabled(self) -> bool:
        return bool(self.anthropic_api_key)


settings = Settings()
