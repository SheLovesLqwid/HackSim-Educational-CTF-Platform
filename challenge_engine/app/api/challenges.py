"""
Challenge generation and validation API endpoints
"""

from fastapi import APIRouter, HTTPException, BackgroundTasks
from pydantic import BaseModel, Field
from typing import Dict, Any, Optional
import structlog
import time

from app.main import get_challenge_generator, get_solution_validator
from app.models.challenge import (
    ChallengeGenerateRequest,
    ChallengeGenerateResponse,
    ChallengeValidateRequest,
    ChallengeValidateResponse
)

logger = structlog.get_logger(__name__)

router = APIRouter()


@router.post("/generate", response_model=ChallengeGenerateResponse)
async def generate_challenge(
    request: ChallengeGenerateRequest,
    background_tasks: BackgroundTasks
):
    """
    Generate a randomized challenge instance from a template.

    This endpoint takes challenge template data and creates a unique instance
    with randomized values while maintaining the same difficulty and structure.
    """
    start_time = time.time()

    try:
        logger.info(
            "Generating challenge instance",
            category=request.category,
            difficulty=request.difficulty,
            base_score=request.base_score
        )

        generator = get_challenge_generator()
        validator = get_solution_validator()

        # Generate the challenge instance
        instance_data = await generator.generate_challenge(
            template_data=request.template_data,
            generator_script=request.generator_script,
            category=request.category,
            difficulty=request.difficulty,
            base_score=request.base_score
        )

        # Validate the generated instance
        if not await validator.validate_generated_challenge(instance_data):
            raise HTTPException(
                status_code=500,
                detail="Generated challenge instance failed validation"
            )

        # Calculate score based on difficulty and complexity
        calculated_score = generator.calculate_score(
            request.base_score,
            request.difficulty,
            instance_data
        )

        # Generate solution hash
        solution_hash = await generator.generate_solution_hash(
            instance_data.get("solution", "")
        )

        # Create response
        response = ChallengeGenerateResponse(
            instance_data=instance_data,
            solution_hash=solution_hash,
            score=calculated_score,
            generated_at=time.time()
        )

        # Log generation in background
        background_tasks.add_task(
            log_challenge_generation,
            request.category,
            request.difficulty,
            calculated_score,
            time.time() - start_time
        )

        logger.info(
            "Challenge generated successfully",
            score=calculated_score,
            generation_time=time.time() - start_time
        )

        return response

    except ValueError as e:
        logger.error("Invalid challenge data", error=str(e))
        raise HTTPException(status_code=400, detail=str(e))

    except RuntimeError as e:
        logger.error("Challenge generation failed", error=str(e))
        raise HTTPException(status_code=500, detail=str(e))

    except Exception as e:
        logger.error("Unexpected error during challenge generation", error=str(e))
        raise HTTPException(status_code=500, detail="Internal server error")


@router.post("/validate", response_model=ChallengeValidateResponse)
async def validate_solution(request: ChallengeValidateRequest):
    """
    Validate a user's solution against a challenge instance.

    This endpoint checks if the submitted solution correctly solves the
    challenge instance and returns the result with any applicable score.
    """
    start_time = time.time()

    try:
        logger.info(
            "Validating solution",
            instance_id=request.instance_id[:8] if request.instance_id else "unknown"
        )

        validator = get_solution_validator()

        # Validate the solution
        result = await validator.validate_solution(
            instance_data=request.instance_data,
            solution_hash=request.solution_hash,
            submission=request.submission,
            validator_script=request.validator_script
        )

        response = ChallengeValidateResponse(
            result=result.result,
            score_earned=result.score_earned,
            execution_log=result.execution_log,
            validation_time=time.time() - start_time
        )

        logger.info(
            "Solution validation completed",
            result=result.result,
            score_earned=result.score_earned,
            validation_time=time.time() - start_time
        )

        return response

    except ValueError as e:
        logger.error("Invalid validation request", error=str(e))
        raise HTTPException(status_code=400, detail=str(e))

    except RuntimeError as e:
        logger.error("Solution validation failed", error=str(e))
        raise HTTPException(status_code=500, detail=str(e))

    except Exception as e:
        logger.error("Unexpected error during validation", error=str(e))
        raise HTTPException(status_code=500, detail="Internal server error")


@router.get("/status/{instance_id}")
async def get_challenge_status(instance_id: str):
    """
    Get the status of a challenge instance or execution.

    This endpoint returns the current status of a challenge instance,
    including whether it's active, completed, or any execution results.
    """
    try:
        logger.info("Getting challenge status", instance_id=instance_id[:8])

        # This would typically check with the execution service
        # For now, return a basic status response
        from app.main import get_redis_client
        redis_client = get_redis_client()

        status_data = await redis_client.get(f"challenge:status:{instance_id}")

        if status_data:
            return {
                "instance_id": instance_id,
                "status": status_data.get("status", "unknown"),
                "updated_at": status_data.get("updated_at"),
                "execution_result": status_data.get("execution_result")
            }
        else:
            return {
                "instance_id": instance_id,
                "status": "not_found",
                "message": "Challenge instance not found or expired"
            }

    except Exception as e:
        logger.error("Error getting challenge status", error=str(e))
        raise HTTPException(status_code=500, detail="Internal server error")


@router.post("/execute")
async def execute_code(
    instance_id: str,
    code: str,
    language: str = "python",
    background_tasks: BackgroundTasks
):
    """
    Execute user code in a secure sandbox environment.

    This endpoint allows for the execution of user-provided code
    within a Docker container with strict resource limits.
    """
    try:
        logger.info(
            "Executing code in sandbox",
            instance_id=instance_id[:8],
            language=language,
            code_length=len(code)
        )

        from app.main import get_execution_service
        execution_service = get_execution_service()

        # Execute the code in sandbox
        result = await execution_service.execute_code(
            code=code,
            language=language,
            instance_id=instance_id,
            timeout=30,  # Default timeout
            memory_limit=512 * 1024 * 1024  # 512MB
        )

        # Log execution in background
        background_tasks.add_task(
            log_code_execution,
            instance_id,
            language,
            result.result,
            result.execution_time
        )

        logger.info(
            "Code execution completed",
            result=result.result,
            execution_time=result.execution_time
        )

        return {
            "instance_id": instance_id,
            "result": result.result,
            "stdout": result.stdout,
            "stderr": result.stderr,
            "execution_time": result.execution_time,
            "memory_used": result.memory_used,
            "exit_code": result.exit_code
        }

    except ValueError as e:
        logger.error("Invalid execution request", error=str(e))
        raise HTTPException(status_code=400, detail=str(e))

    except RuntimeError as e:
        logger.error("Code execution failed", error=str(e))
        raise HTTPException(status_code=500, detail=str(e))

    except Exception as e:
        logger.error("Unexpected error during code execution", error=str(e))
        raise HTTPException(status_code=500, detail="Internal server error")


@router.get("/templates")
async def list_challenge_templates():
    """
    List available challenge templates.

    This endpoint returns a list of available challenge templates
    that can be used to generate new challenge instances.
    """
    try:
        logger.info("Listing challenge templates")

        from app.main import get_redis_client
        redis_client = get_redis_client()

        # Get templates from cache or database
        templates = await redis_client.get("challenge:templates:list") or []

        return {
            "templates": templates,
            "total_count": len(templates),
            "updated_at": time.time()
        }

    except Exception as e:
        logger.error("Error listing templates", error=str(e))
        raise HTTPException(status_code=500, detail="Internal server error")


async def log_challenge_generation(category: str, difficulty: str, score: int, generation_time: float):
    """Log challenge generation for analytics."""
    logger.info(
        "Challenge generation logged",
        category=category,
        difficulty=difficulty,
        score=score,
        generation_time=generation_time
    )


async def log_code_execution(instance_id: str, language: str, result: str, execution_time: float):
    """Log code execution for analytics."""
    logger.info(
        "Code execution logged",
        instance_id=instance_id[:8],
        language=language,
        result=result,
        execution_time=execution_time
    )