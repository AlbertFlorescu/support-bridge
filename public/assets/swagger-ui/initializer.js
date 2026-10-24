window.addEventListener('load', function () {
  window.ui = SwaggerUIBundle({
    url: '/openapi.json',
    dom_id: '#swagger-ui',
    deepLinking: true,
    validatorUrl: null,
    persistAuthorization: false,
    queryConfigEnabled: false,
    withCredentials: false,
    supportedSubmitMethods: [],
    displayRequestDuration: true
  });
});
