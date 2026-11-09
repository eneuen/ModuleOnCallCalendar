// The parts of FullCalendar Standard (MIT) the module page uses, exposed as
// window.FullCalendar: month, week and list views, clicking and dragging,
// and the Russian locale (English is built in).
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import ruLocale from '@fullcalendar/core/locales/ru';

window.FullCalendar = { Calendar, plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin], locales: [ruLocale] };
