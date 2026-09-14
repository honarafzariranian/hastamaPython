FROM python:3.11.0

ENV PYTHONUNBUFFERED 1

WORKDIR /app

COPY pyproject.toml ./
RUN pip install --upgrade pip && \
    pip install uv && \
    uv pip install -e .  # Installs dependencies using uv

ARG DEV=false
RUN if [ "$DEV" = "true" ] ; then uv pip install -e . --group dev ; fi

COPY ./app/ ./
COPY ./ml/model/ ./ml/model/

ENV PYTHONPATH "${PYTHONPATH}:/app"

RUN addgroup --system appgroup && adduser --system --ingroup appgroup appuser
USER appuser

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080')"]

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8080"]