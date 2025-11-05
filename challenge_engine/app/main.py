"""
HackSim Challenge Engine

FastAPI-based microservice for generating, validating, and executing CTF challenges
with secure sandboxing and real-time randomization.
"""

from fastapi import FastAPI, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from contextlib import asynccontextmanager
import structlog
import time
import uuid
from typing import Dict, Any

from app.api import challenges, sandbox, health
from app.core.config import settings
from app.core.logger import setup_logging
from app.services.challenge_generator import ChallengeGenerator
from app.services.sandbox_executor import SandboxExecutor
from app.services.solution_validator import SolutionValidator
from app.services.execution_service import ExecutionService
from app.utils.redis_client import RedisClient

# Setup logging
setup_logging()
logger = structlog.get_logger()

# Global services
challenge_generator: ChallengeGenerator = None
sandbox_executor: SandboxExecutor = None
solution_validator: SolutionValidator = None
execution_service: ExecutionService = None
redis_client: RedisClient = None


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Manage application lifecycle events."""
    logger.info("Starting HackSim Challenge Engine")

    # Initialize services
    global challenge_generator, sandbox_executor, solution_validator, execution_service, redis_client

    try:
        # Initialize Redis client
        redis_client = RedisClient(settings.REDIS_URL)
        await redis_client.connect()
        logger.info("Connected to Redis")

        # Initialize core services
        challenge_generator = ChallengeGenerator(redis_client)
        sandbox_executor = SandboxExecutor(redis_client)
        solution_validator = SolutionValidator()
        execution_service = ExecutionService(sandbox_executor, redis_client)

        logger.info("All services initialized successfully")

        yield

    except Exception as e:
        logger.error(f"Failed to initialize services: {e}")
        raise
    finally:
        logger.info("Shutting down HackSim Challenge Engine")
        if redis_client:
            await redis_client.disconnect()


# Create FastAPI application
app = FastAPI(
    title="HackSim Challenge Engine",
    description="Secure CTF challenge generation and execution service",
    version="1.0.0",
    docs_url="/docs",
    redoc_url="/redoc",
    lifespan=lifespan
)

# Add CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.ALLOWED_HOSTS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.middleware("http")
async def log_requests(request, call_next):
    """Log all incoming requests."""
    start_time = time.time()
    request_id = str(uuid.uuid4())

    # Add request ID to logger context
    logger = structlog.get_logger().bind(request_id=request_id)

    # Log request
    logger.info(
        "Request started",
        method=request.method,
        url=str(request.url),
        client_ip=request.client.host if request.client else None
    )

    try:
        response = await call_next(request)
        process_time = time.time() - start_time

        # Log response
        logger.info(
            "Request completed",
            status_code=response.status_code,
            process_time=process_time
        )

        # Add headers
        response.headers["X-Request-ID"] = request_id
        response.headers["X-Process-Time"] = str(process_time)

        return response

    except Exception as e:
        process_time = time.time() - start_time
        logger.error(
            "Request failed",
            error=str(e),
            process_time=process_time
        )
        raise


@app.exception_handler(Exception)
async def global_exception_handler(request, exc):
    """Handle all unhandled exceptions."""
    logger.error(
        "Unhandled exception",
        exc_info=exc,
        method=request.method,
        url=str(request.url)
    )

    return JSONResponse(
        status_code=500,
        content={
            "error": "Internal server error",
            "message": "An unexpected error occurred while processing your request"
        }
    )


@app.get("/")
async def root():
    """Root endpoint."""
    return {
        "service": "HackSim Challenge Engine",
        "version": "1.0.0",
        "status": "running",
        "timestamp": time.time()
    }


# Include API routers
app.include_router(challenges.router, prefix="/challenge", tags=["challenges"])
app.include_router(sandbox.router, prefix="/sandbox", tags=["sandbox"])
app.include_router(health.router, prefix="/health", tags=["health"])

# Expose services for other modules
def get_challenge_generator() -> ChallengeGenerator:
    return challenge_generator

def get_sandbox_executor() -> SandboxExecutor:
    return sandbox_executor

def get_solution_validator() -> SolutionValidator:
    return solution_validator

def get_execution_service() -> ExecutionService:
    return execution_service

def get_redis_client() -> RedisClient:
    return redis_client


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "app.main:app",
        host="0.0.0.0",
        port=8001,
        reload=settings.DEBUG,
        log_level="info"
    )