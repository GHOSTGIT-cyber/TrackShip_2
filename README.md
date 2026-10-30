<p align="center">
  <img src=".github/readme/banner.svg" alt="TrackShip 2" width="100%">
</p>

> Deuxième version : proxy EuRIS en PHP, tâche planifiée et pilotage d'un relais.

![PHP](https://img.shields.io/badge/PHP-0B1A2A?style=flat-square&labelColor=0B1A2A&color=1E3A52) ![CRON](https://img.shields.io/badge/CRON-0B1A2A?style=flat-square&labelColor=0B1A2A&color=1E3A52) ![DOCKER](https://img.shields.io/badge/DOCKER-0B1A2A?style=flat-square&labelColor=0B1A2A&color=1E3A52)

**Statut** — 🟡 Deuxième itération, remplacée depuis par `trackship-backend` et `trackship-mobile`

---

# TrackShip 2

## Le problème

La première version ne surveillait que si quelqu'un avait la page ouverte. Pour détecter un navire la nuit, il faut quelque chose qui tourne sans personne devant l'écran.

## Ce que fait le projet

- Une tâche planifiée (`cron/tick.php`) qui interroge EuRIS à intervalle régulier
- Un état persistant, pour ne pas renotifier un navire déjà vu
- Le pilotage d'un relais Shelly, afin de déclencher un signal physique à l'arrivée d'un navire
- Le proxy EuRIS repris de la première version

## Structure

`euris-proxy.php` · `cron/tick.php` · `api/state.php` · `api/shelly.php` et `api/shelly-status.php` · `api/auth.php` · `Dockerfile`

## Lancer le projet

`docker build -t trackship2 . && docker run -p 8080:80 trackship2`. Déclarer la tâche planifiée pour appeler `cron/tick.php`.

---

<sub>Développé par **Bakari Abidina** · [bakabi.fr](https://bakabi.fr)</sub>
