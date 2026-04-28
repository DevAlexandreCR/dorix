export interface HealthResponse {
  status: string;
  application: string;
  environment: string;
  timestamp: string;
  stack: {
    api: string;
    frontend: string;
    database: string;
    queue: string;
    cache: string;
  };
}

export interface MetaResponse {
  name: string;
  api: {
    version: string;
    base_path: string;
    health_path: string;
    auth: string;
    tenancy: string;
  };
  frontend: {
    framework: string;
    module_root: string;
  };
  backend: {
    domain_root: string;
    tenant_support_root: string;
  };
}

