{{/*
Chart name and common labels.
*/}}
{{- define "travianz.name" -}}
{{- .Chart.Name | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "travianz.fullname" -}}
{{- printf "%s" .Release.Name | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "travianz.labels" -}}
app.kubernetes.io/name: {{ include "travianz.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
helm.sh/chart: {{ printf "%s-%s" .Chart.Name .Chart.Version }}
{{- end }}

{{- define "travianz.selectorLabels" -}}
app.kubernetes.io/name: {{ include "travianz.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
MariaDB primary service name: game pods always connect here.
*/}}
{{- define "travianz.mariadbHost" -}}
{{- printf "%s-mariadb-primary" (include "travianz.fullname" .) }}
{{- end }}

{{/*
config.php renderer.
The chart ships a proven-working generated config.php (files/config.php.tpl,
exported from the reference docker-compose install) with Helm placeholders in
the environment-specific spots (DB host/creds, URLs, server name, cron key).
tpl() expands them. The result is mounted from a Secret because it contains
SQL_PASS and CRON_KEY.
*/}}
{{- define "travianz.configPhp" -}}
{{- tpl (.Files.Get "files/config.php.tpl") . -}}
{{- end }}
