"""
Application configuration settings
"""

from pydantic_settings import BaseSettings
from typing import List, Optional
import os


class Settings(BaseSettings):
    """Application settings."""

    # Application
    APP_NAME: str = "HackSim Challenge Engine"
    VERSION: str = "1.0.0"
    DEBUG: bool = False
    ENVIRONMENT: str = "production"

    # Server
    HOST: str = "0.0.0.0"
    PORT: int = 8001
    WORKERS: int = 1

    # CORS
    ALLOWED_HOSTS: List[str] = ["*"]

    # Redis
    REDIS_URL: str = "redis://localhost:6379/0"
    REDIS_PREFIX: str = "hacksim:challenge_engine:"

    # Docker
    DOCKER_HOST: str = "unix:///var/run/docker.sock"
    DOCKER_TIMEOUT: int = 30

    # Sandbox
    SANDBOX_MODE: str = "safe"  # safe, permissive, custom
    MAX_EXECUTION_TIME: int = 30  # seconds
    MAX_MEMORY_MB: int = 512
    MAX_CPU_CORES: int = 1
    MAX_PROCESSES: int = 10
    MAX_FILE_SIZE_MB: int = 10

    # Security
    SECRET_KEY: str = "change-me-in-production"
    JWT_ALGORITHM: str = "HS256"
    JWT_EXPIRE_MINUTES: int = 1440  # 24 hours

    # Challenge Generation
    CHALLENGE_CACHE_TTL: int = 3600  # 1 hour
    MAX_RANDOMIZATION_ATTEMPTS: int = 100
    SOLUTION_HASH_ALGORITHM: str = "sha256"

    # Logging
    LOG_LEVEL: str = "INFO"
    LOG_FORMAT: str = "json"

    # Health checks
    HEALTH_CHECK_INTERVAL: int = 30  # seconds
    MAX_CONTAINER_STARTUP_TIME: int = 60  # seconds

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"
        case_sensitive = True


# Create settings instance
settings = Settings()