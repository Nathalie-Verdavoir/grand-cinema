import { Application } from '@hotwired/stimulus';
import CashRegisterController from './controllers/cash-register_controller.js';
import FilmCrudController from './controllers/search-film_controller.js';
import FilmNextController from './controllers/film-next_controller.js';
import SeancePlannerController from './controllers/seance-planner_controller.js';
import './flash-messages.js';
import './styles/tokens.css';
import './styles/admin.css';
import './styles/theme.css';
import './styles/flash-messages.css';

const app = Application.start();
app.register('cash-register', CashRegisterController);
app.register('search-film', FilmCrudController);
app.register('film-next', FilmNextController);
app.register('seance-planner', SeancePlannerController);