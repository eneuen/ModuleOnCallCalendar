// The parts of FullCalendar (MIT) the module page uses, exposed as
// window.FullCalendar: month, week and list views, clicking and dragging,
// the Pulse theme, and the Russian locale (English is built in).
import { Calendar } from 'fullcalendar';
import dayGridPlugin from 'fullcalendar/daygrid';
import timeGridPlugin from 'fullcalendar/timegrid';
import listPlugin from 'fullcalendar/list';
import interactionPlugin from 'fullcalendar/interaction';
import pulseTheme from 'fullcalendar/themes/pulse';
import ruLocale from 'fullcalendar/locales/ru';

window.FullCalendar = { Calendar, plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin, pulseTheme], locales: [ruLocale] };
