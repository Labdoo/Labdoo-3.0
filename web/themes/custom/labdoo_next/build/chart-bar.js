import { Chart, BarController, BarElement, CategoryScale, LinearScale, Colors, Legend, Tooltip, Title, SubTitle } from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Colors, Legend, Tooltip, Title, SubTitle);
window.Chart = Chart;
