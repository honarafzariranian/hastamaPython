# testapp

short test app

## Development Requirements

- Python 3.11+
- Uv (Python Package Manager)

### M.L Model Environment

```sh
MODEL_PATH=./ml/model/
MODEL_NAME=model.pkl
```

### Update `/predict`

To update your machine learning model, add your `load` and `method` [change here](app/api/routes/predictor.py#L19) at `predictor.py`

## Installation

```sh
python -m venv venv
source venv/bin/activate
make install
```

## Runnning Localhost

`make run`

#### اعلان Chrome و Web Push

اعلان داخلی سامانه از inbox پایدار خوانده می‌شود و Web Push اختیاری آن با Service Worker
می‌تواند حتی در تب پس‌زمینه، پنجره‌ی Minimize‌شده و نرم‌افزار دیگر Windows اعلان سیستم‌عامل را نشان دهد.
برای فعال‌سازی، پکیج پروژه را نصب کنید و این مقادیر را فقط در `.env` سمت سرور قرار دهید:

```env
VAPID_PUBLIC_KEY=<public-key>
VAPID_PRIVATE_KEY=<private-key>
VAPID_SUBJECT=mailto:admin@example.com

```

روی `localhost`، HTTP یک Secure Context محسوب می‌شود. برای استفاده‌ی شبکه‌ای، HTTP با IP
مناسب Web Push نیست و باید HTTPS معتبر داشته باشید؛ ترجیحاً یک نام داخلی مانند
`hastama.local` با گواهی صادرشده برای همان نام (یا گواهی دارای IP در صورت پشتیبانی CA).
گواهی CA باید روی تمام Clientها Trusted شود. سپس:

```sh
SSL_CERTFILE=/path/to/cert.pem SSL_KEYFILE=/path/to/key.pem make run
```

پس از ورود، یک‌بار روی زنگ اعلان کلیک و مجوز را Allow کنید. Service Worker از مسیر
`/static/js/hastama-sw.js` ثبت می‌شود و Subscription به کاربر نشست‌شده متصل است.
اگر Permission قبلاً رد شده، آن را از Site settings > Notifications در Chrome فعال کنید.

ساخت کلید VAPID را با ابزار امن Web Push انجام دهید؛ کلید خصوصی هرگز در Frontend، لاگ یا Git قرار نگیرد.

## Deploy app

`make deploy`

## Running Tests

`make test`

## Access Swagger Documentation

> <http://localhost:8080/docs>

## Access Redocs Documentation

> <http://localhost:8080/redoc>

## Project structure

Files related to application are in the `app` or `tests` directories.
Application parts are:

    app
    |
    | # Fast-API stuff
    ├── api                 - web related stuff.
    │   └── routes          - web routes.
    ├── core                - application configuration, startup events, logging.
    ├── models              - pydantic models for this application.
    ├── services            - logic that is not just crud related.
    ├── main-aws-lambda.py  - [Optional] FastAPI application for AWS Lambda creation and configuration.
    └── main.py             - FastAPI application creation and configuration.
    |
    | # ML stuff
    ├── data             - where you persist data locally
    │   ├── interim      - intermediate data that has been transformed.
    │   ├── processed    - the final, canonical data sets for modeling.
    │   └── raw          - the original, immutable data dump.
    │
    ├── notebooks        - Jupyter notebooks. Naming convention is a number (for ordering),
    |
    ├── ml               - modelling source code for use in this project.
    │   ├── __init__.py  - makes ml a Python module
    │   ├── pipeline.py  - scripts to orchestrate the whole pipeline
    │   │
    │   ├── data         - scripts to download or generate data
    │   │   └── make_dataset.py
    │   │
    │   ├── features     - scripts to turn raw data into features for modeling
    │   │   └── build_features.py
    │   │
    │   └── model        - scripts to train models and make predictions
    │       ├── predict_model.py
    │       └── train_model.py
    │
    └── tests            - pytest

## GCP

Deploying inference service to Cloud Run

### Authenticate

1. Install `gcloud` cli
2. `gcloud auth login`
3. `gcloud config set project <PROJECT_ID>`

### Enable APIs

1. Cloud Run API
2. Cloud Build API
3. IAM API

### Deploy to Cloud Run

1. Run `gcp-deploy.sh`

### Clean up

1. Delete Cloud Run
2. Delete Docker image in GCR

## AWS

Deploying inference service to AWS Lambda

### Authenticate

1. Install `awscli` and `sam-cli`
2. `aws configure`

### Deploy to Lambda

1. Run `sam build`
2. Run `sam deploy --guiChange this portion for other types of models

## Add the correct type hinting when completed

`aws cloudformation delete-stack --stack-name <STACK_NAME_ON_CREATION>`

Made by <https://github.com/arthurhenrique/cookiecutter-fastapi/graphs/contributors> with ❤️
