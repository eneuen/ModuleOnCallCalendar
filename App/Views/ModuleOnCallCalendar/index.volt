{#
  On Call Calendar page shell. No schedule, employee or route data is
  rendered here: the script loads it as JSON and inserts it as text only.
#}
<div id="on-call-calendar" class="occ-page">
    <div class="ui negative message occ-hidden" id="occ-error"></div>
    <div class="ui positive message occ-hidden occ-flash" id="occ-flash"></div>
    <div id="occ-main">
        <div class="ui active centered inline loader"></div>
    </div>
</div>
