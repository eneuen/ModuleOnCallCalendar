{# On Call Calendar: the schedules and their numbers. Every value is escaped with |e. #}
{% if schedules is empty %}
<div class="ui info message">
    <div class="header">{{ t._('occ_NoSchedules')|e }}</div>
    <p>{{ t._('occ_NoSchedulesHint')|e }}</p>
</div>
{% else %}
<table class="ui very basic compact table">
    <thead>
        <tr>
            <th>{{ t._('occ_Schedule')|e }}</th>
            <th>{{ t._('occ_Number')|e }}</th>
            <th>{{ t._('occ_Status')|e }}</th>
        </tr>
    </thead>
    <tbody>
    {% for schedule in schedules %}
        <tr>
            <td>{{ schedule['name']|e }}</td>
            <td>{{ schedule['number']|e }}</td>
            <td>{{ (schedule['enabled'] == '1' ? t._('occ_Enabled') : t._('occ_Disabled'))|e }}</td>
        </tr>
    {% endfor %}
    </tbody>
</table>
{% endif %}
